/**
 * Content Tracker Sync — Admin JS
 *
 * Handles the "Sync to Tracker" button click in both the Classic Editor
 * and the Gutenberg Block Editor.
 *
 * @package ContentTrackerSync
 */

(function ($) {
    'use strict';

    /**
     * Read tags from Classic Editor using multiple fallback strategies.
     *
     * @returns {string[]} Unique tag names.
     */
    function getClassicEditorTags() {
        var tags = [];

        // Method 1: Read from the hidden tag textarea (most reliable).
        // WordPress uses several possible selectors depending on version.
        var $input = $('textarea.the-tags, #tax-input-post_tag, textarea[name="tax_input[post_tag]"]');
        if ($input.length) {
            $input.each(function () {
                var val = ($(this).val() || '').trim();
                if (val) {
                    var parsed = val.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
                    tags = tags.concat(parsed);
                }
            });
        }

        // Method 2: Read from the tag input field (user may have typed but not clicked Add).
        var $newTagInput = $('#new-tag-post_tag, input.newtag');
        if ($newTagInput.length) {
            var newVal = ($newTagInput.val() || '').trim();
            if (newVal) {
                var newParsed = newVal.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
                tags = tags.concat(newParsed);
            }
        }

        // Method 3: Parse from tagchecklist children (strip remove buttons).
        $('#tagsdiv-post_tag .tagchecklist > li, #tagsdiv-post_tag .tagchecklist > span').each(function () {
            var $el = $(this);
            // Clone to avoid mutating the DOM.
            var $clone = $el.clone();
            $clone.find('button, a, .ntdelbutton, .remove-tag-icon, .screen-reader-text, .dashicons').remove();
            var text = $clone.text().replace(/×/g, '').replace(/✕/g, '').replace(/X/g, '').trim();
            // Avoid picking up stray single characters from button remnants.
            if (text && text.length > 0) {
                tags.push(text);
            }
        });

        // Method 4: Read from the Most Used tags panel (checked checkboxes).
        $('#tagsdiv-post_tag .tagcloud a.tag-link-check, #tagsdiv-post_tag input[type="checkbox"]:checked').each(function () {
            var text = $(this).text().trim() || $(this).closest('label').text().trim();
            if (text) {
                tags.push(text);
            }
        });

        // Deduplicate (case-insensitive) and return.
        var seen = {};
        return tags.filter(function (t) {
            var lower = t.toLowerCase();
            if (seen[lower]) return false;
            seen[lower] = true;
            return true;
        });
    }

    /**
     * Read tag term IDs from Gutenberg editor store.
     * Uses multiple strategies to capture tags even after savePost().
     *
     * @returns {number[]} Tag term IDs.
     */
    function getGutenbergTagIds() {
        if (typeof wp === 'undefined' || !wp.data || !wp.data.select) {
            return [];
        }

        var ids = [];

        try {
            // Strategy 1: Edited (unsaved) post attributes — best for pre-save.
            var editedIds = wp.data.select('core/editor').getEditedPostAttribute('tags');
            if (Array.isArray(editedIds) && editedIds.length > 0) {
                ids = ids.concat(editedIds);
            }
        } catch (e) { /* ignore */ }

        try {
            // Strategy 2: Current (saved) post object — best for post-save.
            var currentPost = wp.data.select('core/editor').getCurrentPost();
            if (currentPost && Array.isArray(currentPost.tags) && currentPost.tags.length > 0) {
                ids = ids.concat(currentPost.tags);
            }
        } catch (e) { /* ignore */ }

        try {
            // Strategy 3: Core entity store — another way to get post data.
            var postId = wp.data.select('core/editor').getCurrentPostId();
            var postType = wp.data.select('core/editor').getCurrentPostType();
            if (postId && postType) {
                var record = wp.data.select('core').getEditedEntityRecord('postType', postType, postId);
                if (record && Array.isArray(record.tags) && record.tags.length > 0) {
                    ids = ids.concat(record.tags);
                }
            }
        } catch (e) { /* ignore */ }

        // Deduplicate.
        return ids.filter(function (id, index, arr) {
            return arr.indexOf(id) === index && id > 0;
        });
    }

    /**
     * Try to resolve tag names from Gutenberg entity records.
     *
     * @returns {string[]} Tag names if available.
     */
    function getGutenbergTagNames() {
        if (typeof wp === 'undefined' || !wp.data || !wp.data.select) {
            return [];
        }

        var names = [];

        try {
            var tagIds = getGutenbergTagIds();
            if (tagIds.length === 0) return [];

            // Try to resolve each tag ID from the core store.
            tagIds.forEach(function (id) {
                var tag = wp.data.select('core').getEntityRecord('taxonomy', 'post_tag', id);
                if (tag && tag.name) {
                    names.push(tag.name);
                }
            });
        } catch (e) { /* ignore */ }

        return names;
    }

    /**
     * Gather tag data from whichever editor is active.
     * Collects from ALL available sources for maximum reliability.
     *
     * @returns {{ names: string[], ids: number[] }}
     */
    function getCurrentTagData() {
        var classicTags = getClassicEditorTags();
        var gutenbergIds = getGutenbergTagIds();
        var gutenbergNames = getGutenbergTagNames();

        // Merge classic editor tags + Gutenberg resolved names.
        var allNames = classicTags.concat(gutenbergNames);

        // Deduplicate names (case-insensitive).
        var seen = {};
        allNames = allNames.filter(function (t) {
            var lower = t.toLowerCase();
            if (seen[lower]) return false;
            seen[lower] = true;
            return true;
        });

        return {
            names: allNames,
            ids: gutenbergIds,
        };
    }

    /**
     * Perform the AJAX sync request.
     *
     * @param {HTMLButtonElement} button  The sync button element.
     * @param {number}            postId  WordPress post ID.
     */
    function performSync(button, postId) {
        var $button = $(button);
        var $status = $('#cts-sync-status');
        var tagData = getCurrentTagData();

        // Prevent double-clicks.
        if ($button.prop('disabled')) {
            return;
        }

        $button.prop('disabled', true).text(ctsData.strings.syncing);
        $status.html('').removeClass('cts-status-success cts-status-error');

        // Debug: Log tag data to console for troubleshooting.
        if (window.console && console.log) {
            console.log('[CTS] Tag data being sent:', JSON.stringify(tagData));
        }

        $.ajax({
            url: ctsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cts_sync_post',
                nonce: ctsData.nonce,
                post_id: postId,
                tags: tagData.names,
                tag_ids: tagData.ids,
            },
            success: function (response) {
                if (response.success) {
                    $status
                        .addClass('cts-status-success')
                        .html('<span>✓ ' + response.data.message + '</span>');
                    // Smooth blue → green animation.
                    $button.addClass('cts-btn-success');
                    setTimeout(function () {
                        $button.removeClass('cts-btn-success');
                    }, 3000);
                } else {
                    var msg = response.data && response.data.message
                        ? response.data.message
                        : ctsData.strings.error;
                    $status
                        .addClass('cts-status-error')
                        .html('<span>✗ ' + msg + '</span>');
                }
            },
            error: function () {
                $status
                    .addClass('cts-status-error')
                    .html('<span>✗ ' + ctsData.strings.error + '</span>');
            },
            complete: function () {
                $button.prop('disabled', false).text(ctsData.strings.btnLabel);
            },
        });
    }

    /*--------------------------------------------------------------
     * Classic Editor — meta box button
     *------------------------------------------------------------*/
    $(document).on('click', '#cts-sync-button', function (e) {
        e.preventDefault();
        var postId = ctsData.postId || $('#post_ID').val();
        performSync(this, postId);
    });

    /*--------------------------------------------------------------
     * Gutenberg Block Editor — PluginPostStatusInfo panel
     *------------------------------------------------------------*/
    $(document).ready(function () {

        // Only proceed if we are in the block editor.
        if (typeof wp === 'undefined' || !wp.plugins || !wp.element || !wp.components || !wp.data) {
            return;
        }

        // PluginPostStatusInfo moved from wp.editPost to wp.editor in WP 6.6+.
        var PluginPostStatusInfo =
            (wp.editor && wp.editor.PluginPostStatusInfo) ||
            (wp.editPost && wp.editPost.PluginPostStatusInfo);

        if (!PluginPostStatusInfo) {
            return; // Neither API available — bail gracefully.
        }

        var el = wp.element.createElement;
        var Fragment = wp.element.Fragment;
        var Button = wp.components.Button;
        var registerPlugin = wp.plugins.registerPlugin;
        var useState = wp.element.useState;
        var select = wp.data.select;

        /**
         * Gutenberg panel component.
         */
        function CTSSyncPanel() {
            var _state = useState('idle');
            var status = _state[0];
            var setStatus = _state[1];

            var _msgState = useState('');
            var message = _msgState[0];
            var setMessage = _msgState[1];

            function doAjaxSync(postId, setStatus, setMessage) {
                var tagData = getCurrentTagData();

                // Debug: Log tag data for troubleshooting.
                if (window.console && console.log) {
                    console.log('[CTS Gutenberg] Tag data being sent:', JSON.stringify(tagData));
                }

                $.ajax({
                    url: ctsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'cts_sync_post',
                        nonce: ctsData.nonce,
                        post_id: postId,
                        tags: tagData.names,
                        tag_ids: tagData.ids,
                    },
                    success: function (response) {
                        if (response.success) {
                            setStatus('success');
                            setMessage(response.data.message);
                        } else {
                            setStatus('error');
                            setMessage(
                                response.data && response.data.message
                                    ? response.data.message
                                    : ctsData.strings.error
                            );
                        }
                    },
                    error: function () {
                        setStatus('error');
                        setMessage(ctsData.strings.error);
                    },
                });
            }

            function handleClick() {
                var postId = select('core/editor').getCurrentPostId();
                setStatus('syncing');
                setMessage('');

                // Save the post first so tags are persisted in the DB,
                // then wait a brief moment for the DB to update, then sync.
                if (wp.data && wp.data.dispatch) {
                    try {
                        wp.data.dispatch('core/editor').savePost()
                            .then(function () {
                                // Small delay to ensure DB has committed tag relationships.
                                setTimeout(function () {
                                    doAjaxSync(postId, setStatus, setMessage);
                                }, 500);
                            })
                            .catch(function () {
                                // Even if save fails, attempt sync with current data.
                                doAjaxSync(postId, setStatus, setMessage);
                            });
                    } catch (e) {
                        doAjaxSync(postId, setStatus, setMessage);
                    }
                } else {
                    doAjaxSync(postId, setStatus, setMessage);
                }
            }

            return el(
                PluginPostStatusInfo,
                { className: 'cts-gutenberg-panel' },
                el(
                    Fragment,
                    null,
                    el(
                        'div',
                        { style: { width: '100%' } },
                        el(
                            'p',
                            { className: 'cts-panel-note' },
                            'Sync this post to your editorial Google Sheet.'
                        ),
                        el(
                            'p',
                            { className: 'cts-panel-credit' },
                            'Built by Krish Goswami'
                        ),
                        el(
                            Button,
                            {
                                variant: 'primary',
                                isBusy: status === 'syncing',
                                disabled: status === 'syncing',
                                onClick: handleClick,
                                style: {
                                    width: '100%',
                                    justifyContent: 'center',
                                    transition: 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)',
                                    background: status === 'success'
                                        ? 'linear-gradient(135deg, #00a32a 0%, #008a20 100%)'
                                        : undefined,
                                },
                            },
                            status === 'syncing'
                                ? ctsData.strings.syncing
                                : status === 'success'
                                    ? '✓ Synced!'
                                    : ctsData.strings.btnLabel
                        ),
                        status === 'success'
                            ? el(
                                'p',
                                { className: 'cts-status-success', style: { marginTop: '8px' } },
                                '✓ ' + message
                            )
                            : null,
                        status === 'error'
                            ? el(
                                'p',
                                { className: 'cts-status-error', style: { marginTop: '8px' } },
                                '✗ ' + message
                            )
                            : null
                    )
                )
            );
        }

        registerPlugin('content-tracker-sync', {
            render: CTSSyncPanel,
            icon: 'update',
        });
    });

})(jQuery);
