define(['view'], (View) => {
    const empty = (val) => {
        if (val === undefined || val === null) return true;
        if (typeof val === 'string' && val.trim() === '') return true;
        if (Array.isArray(val) && val.length === 0) return true;
        if (typeof val === 'object' && Object.keys(val).length === 0) return true;
        return false;
    };

    const round = (val, precision = 0) => {
        const factor = Math.pow(10, precision);
        return Math.round(val * factor) / factor;
    };

    return class extends View {
        template = 'whatsapp:whatsapp';

        // Bind UI events
        events = {
            'click [data-tab]': 'onTabClick',
            'click .chat-item': 'onConversationSelect',
            'click #send-msg-btn': 'onSendMessage',
            'keypress #chat-editor': 'onEditorKeyPress',
            'input #chat-editor': 'onEditorInput',
            'click .canned-item': 'onCannedSelect',
            'click #resolve-btn': 'onResolveConversation',
            'click #reopen-btn': 'onReopenConversation',
            'change #reassign-select': 'onReassignOwner',
            'click #sync-templates-btn': 'onSyncTemplates',
            'click #create-campaign-btn': 'onShowCampaignForm',
            'submit #campaign-form': 'onLaunchCampaign',
            'click #save-settings-btn': 'onSubmitSettings',
            'click #copy-webhook-url': 'onCopyWebhookUrl',
            'click #copy-webhook-token': 'onCopyWebhookToken',
            'click #toggle-internal-note': 'onToggleInternalNote',
            'click #canned-manager-btn': 'onShowCannedManager',
            'submit #canned-form': 'onSaveCannedResponse',
            'click #template-msg-btn': 'onShowTemplateSelector',
            'change #template-select-picker': 'onTemplatePickerChange',
            'click #send-template-test-btn': 'onSendTemplateMessage',
            'click #media-attach-btn': 'onTriggerFilePicker',
            'change #media-file-input': 'onUploadMediaAttachment',
            'click #refresh-logs-btn': 'fetchLogs',
            'change #logs-status-filter': 'fetchLogs',
            'input #logs-search': 'fetchLogs'
        };

        setup() {
            this.activeTab = 'inbox'; // inbox, templates, campaigns, analytics, settings
            this.conversations = [];
            this.messages = [];
            this.templates = [];
            this.campaigns = [];
            this.cannedResponses = [];
            this.logs = [];
            this.selectedConv = null;
            this.isInternalNoteActive = false;

            // Initialize real-time updates (SSE with automatic polling fallback)
            this.initRealtime();
            
            // Initial data pull
            this.fetchConversations(true);
            this.fetchTemplates();
            this.fetchCampaigns();
            this.fetchCannedResponses();
            this.fetchSettings();
        }

        initRealtime() {
            if (typeof EventSource !== 'undefined') {
                this.initSSE();
            } else {
                this.initPolling();
            }
        }

        initSSE() {
            if (this.sseSource) {
                this.sseSource.close();
            }

            const siteUrl = this.getBasePath() || '';
            const convIdParam = this.selectedConv ? `&conversationId=${this.selectedConv.id}` : '';
            const sseUrl = `${siteUrl}?entryPoint=WhatsAppRealtime${convIdParam}`;

            this.sseSource = new EventSource(sseUrl);

            this.sseSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (this.activeTab === 'inbox') {
                        if (data.conversations) {
                            this.fetchConversations(false);
                        }
                        if (data.messages && this.selectedConv) {
                            this.fetchMessages(this.selectedConv.id, false);
                        }
                    }
                } catch (err) {
                    console.error("SSE parsing error:", err);
                }
            };

            this.sseSource.onerror = () => {
                console.warn("SSE stream error. Gracefully falling back to 4s polling.");
                if (this.sseSource) {
                    this.sseSource.close();
                    this.sseSource = null;
                }
                this.initPolling();
            };
        }

        initPolling() {
            if (this.pollingInterval) {
                return; // already polling
            }

            this.pollingInterval = setInterval(() => {
                if (this.activeTab === 'inbox') {
                    this.fetchConversations(false);
                    if (this.selectedConv) {
                        this.fetchMessages(this.selectedConv.id, false);
                    }
                }
            }, 4000);
        }

        onDestroy() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }
            if (this.sseSource) {
                this.sseSource.close();
            }
        }

        afterRender() {
            this.switchTabUI(this.activeTab);
        }

        // --- Core Fetch Methods ---

        fetchConversations(triggerRender = true) {
            const filter = this.$('#inbox-filter-select').val() || 'all';
            const search = this.$('#inbox-search').val() || '';
            const currentUserId = this.getUser().id;

            this.getHelper().ajax({
                type: 'GET',
                url: `WhatsApp/conversations?filter=${filter}&search=${encodeURIComponent(search)}&currentUserId=${currentUserId}`
            }).then((data) => {
                // If conversations loaded differ in unread count or time, update list
                if (JSON.stringify(this.conversations) !== JSON.stringify(data)) {
                    this.conversations = data;
                    this.renderConversations();
                }
            });
        }

        fetchMessages(conversationId, triggerScroll = true) {
            this.getHelper().ajax({
                type: 'GET',
                url: `WhatsApp/messages?conversationId=${conversationId}`
            }).then((data) => {
                if (JSON.stringify(this.messages) !== JSON.stringify(data)) {
                    this.messages = data;
                    this.renderMessages(triggerScroll);
                }
            });
        }

        fetchTemplates() {
            this.getHelper().ajax({
                type: 'GET',
                url: 'WhatsApp/templates'
            }).then((data) => {
                this.templates = data;
                this.renderTemplates();
                this.populateTemplatePicker();
            });
        }

        fetchCampaigns() {
            this.getHelper().ajax({
                type: 'GET',
                url: 'WhatsApp/campaigns'
            }).then((data) => {
                this.campaigns = data;
                this.renderCampaigns();
            });
        }

        fetchCannedResponses() {
            this.getHelper().ajax({
                type: 'GET',
                url: 'WhatsApp/cannedResponses'
            }).then((data) => {
                this.cannedResponses = data;
                this.renderCannedList();
            });
        }

        fetchSettings() {
            this.getHelper().ajax({
                type: 'GET',
                url: 'WhatsApp/settings'
            }).then((data) => {
                this.settings = data;
                this.renderSettings();
            });
        }

        // --- UI Rendering Helpers ---

        switchTabUI(tab) {
            this.$('[data-tab]').removeClass('active-tab');
            this.$(`[data-tab="${tab}"]`).addClass('active-tab');

            this.$('.workspace-pane').hide();
            this.$(`#pane-${tab}`).fadeIn(200);

            if (tab === 'inbox') {
                this.fetchConversations(true);
            } else if (tab === 'templates') {
                this.fetchTemplates();
            } else if (tab === 'campaigns') {
                this.fetchCampaigns();
            } else if (tab === 'analytics') {
                this.renderAnalytics();
            } else if (tab === 'settings') {
                this.fetchSettings();
            } else if (tab === 'logs') {
                this.fetchLogs();
            }
        }

        onTabClick(e) {
            const tab = $(e.currentTarget).data('tab');
            this.activeTab = tab;
            this.switchTabUI(tab);
        }

        renderConversations() {
            const $list = this.$('#conversation-list-container');
            $list.empty();

            if (this.conversations.length === 0) {
                $list.html('<div class="empty-list-notice">No conversations found.</div>');
                return;
            }

            this.conversations.forEach((conv) => {
                const isActive = this.selectedConv && this.selectedConv.id === conv.id ? 'active-chat' : '';
                const unreadBadge = conv.unreadCount > 0 ? `<span class="unread-badge animate__animated animate__bounceIn">${conv.unreadCount}</span>` : '';
                
                const time = conv.lastMessageTime ? new Date(conv.lastMessageTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                
                // Mapped badge color
                const crmBadge = conv.mappedType !== 'None' ? `<span class="crm-pill crm-${conv.mappedType.toLowerCase()}">${conv.mappedType}</span>` : '';

                $list.append(`
                    <div class="chat-item ${isActive}" data-id="${conv.id}">
                        <div class="chat-item-avatar">
                            <span class="chat-avatar-text">${conv.customerName.substring(0, 2).toUpperCase()}</span>
                        </div>
                        <div class="chat-item-details">
                            <div class="chat-item-header">
                                <span class="customer-name">${conv.customerName}</span>
                                <span class="chat-time">${time}</span>
                            </div>
                            <div class="chat-item-footer">
                                <span class="chat-snippet">${conv.lastMessageSnippet || 'No messages yet'}</span>
                                <div class="chat-status-badges">
                                    ${crmBadge}
                                    ${unreadBadge}
                                </div>
                            </div>
                        </div>
                    </div>
                `);
            });
        }

        onConversationSelect(e) {
            const id = $(e.currentTarget).data('id');
            this.selectedConv = this.conversations.find(c => c.id === id);
            this.$('.chat-item').removeClass('active-chat');
            $(e.currentTarget).addClass('active-chat');

            this.$('#chat-workspace-placeholder').hide();
            this.$('#chat-workspace-active').show();

            // Load headers & metadata
            this.$('#active-chat-title').text(this.selectedConv.customerName);
            this.$('#active-chat-phone').text('+' + this.selectedConv.phoneNumber);
            
            // Map CRM Record Links
            if (this.selectedConv.mappedType !== 'None') {
                this.$('#chat-crm-link').html(`
                    <a href="#${this.selectedConv.mappedType}/view/${this.selectedConv.mappedId}" class="btn btn-sm btn-glass flex items-center gap-1">
                        <i class="fas fa-external-link-alt"></i> View ${this.selectedConv.mappedType}
                    </a>
                `).show();
            } else {
                this.$('#chat-crm-link').hide();
            }

            // Assign status controls
            if (this.selectedConv.status === 'Resolved') {
                this.$('#resolve-btn').hide();
                this.$('#reopen-btn').show();
            } else {
                this.$('#resolve-btn').show();
                this.$('#reopen-btn').hide();
            }

            this.fetchMessages(id, true);

            // Re-establish SSE connection with the newly selected conversation context
            if (this.sseSource) {
                this.initSSE();
            }
        }

        renderMessages(triggerScroll = true) {
            const $stream = this.$('#chat-messages-stream');
            $stream.empty();

            if (this.messages.length === 0) {
                $stream.html('<div class="empty-list-notice">Starting a conversation...</div>');
                return;
            }

            this.messages.forEach((msg) => {
                const isOutgoing = msg.direction === 'Outgoing';
                const bubbleClass = msg.isInternalNote ? 'message-internal-note' : (isOutgoing ? 'message-outgoing' : 'message-incoming');
                const avatar = isOutgoing ? 'A' : this.selectedConv.customerName.substring(0, 2).toUpperCase();
                const senderName = msg.isInternalNote ? `[Private Note] ${msg.senderName || 'Agent'}` : (isOutgoing ? (msg.senderName || 'Agent') : this.selectedConv.customerName);

                // Tick Status Indicators
                let ticks = '';
                if (isOutgoing && !msg.isInternalNote) {
                    if (msg.status === 'Read') {
                        ticks = '<i class="fas fa-check-double text-blue-500 text-xs"></i>';
                    } else if (msg.status === 'Delivered') {
                        ticks = '<i class="fas fa-check-double text-gray-400 text-xs"></i>';
                    } else if (msg.status === 'Failed') {
                        ticks = '<i class="fas fa-exclamation-circle text-red-500 text-xs" title="' + (msg.errorMessage || 'Failed to deliver') + '"></i>';
                    } else {
                        ticks = '<i class="fas fa-check text-gray-400 text-xs"></i>';
                    }
                }

                // Render Content media previews
                let mediaHtml = '';
                if (msg.mediaUrl) {
                    const serveUrl = msg.mediaServeUrl || msg.mediaUrl;
                    const type = msg.type.toLowerCase();

                    if (type === 'image') {
                        mediaHtml = `<div class="media-preview-container"><img src="${serveUrl}" class="media-preview-image" alt="Image attachment" onclick="window.open('${serveUrl}', '_blank')"/></div>`;
                    } else if (type === 'document' || type === 'pdf') {
                        mediaHtml = `<div class="media-preview-doc"><i class="fas fa-file-pdf text-red-500 text-2xl"></i> <a href="${serveUrl}" target="_blank" class="underline text-sm font-semibold">Preview Document</a></div>`;
                    } else if (type === 'audio') {
                        mediaHtml = `<div class="media-preview-audio"><audio controls class="max-w-full"><source src="${serveUrl}" type="${msg.mediaMimeType || 'audio/ogg'}"/></audio></div>`;
                    } else if (type === 'video') {
                        mediaHtml = `<div class="media-preview-video"><video controls class="media-preview-vid"><source src="${serveUrl}" type="${msg.mediaMimeType || 'video/mp4'}"/></video></div>`;
                    }
                }

                const textFormatted = this.formatMessageText(msg.textContent || '');

                $stream.append(`
                    <div class="message-bubble-wrapper ${isOutgoing ? 'justify-end' : 'justify-start'} animate__animated animate__fadeInUp">
                        <div class="message-bubble ${bubbleClass}">
                            <div class="message-meta-header">
                                <span class="message-sender">${senderName}</span>
                                <span class="message-time">${new Date(msg.createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                            </div>
                            ${mediaHtml}
                            <div class="message-text-content">${textFormatted}</div>
                            <div class="message-meta-footer justify-end">
                                ${ticks}
                            </div>
                        </div>
                    </div>
                `);
            });

            if (triggerScroll) {
                setTimeout(() => {
                    $stream.scrollTop($stream[0].scrollHeight);
                }, 50);
            }
        }

        formatMessageText(text) {
            // Basic WhatsApp text format parsing
            let formatted = text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                // Bold formatting *text*
                .replace(/\*([^*]+)\*/g, '<strong>$1</strong>')
                // Italics _text_
                .replace(/_([^_]+)_/g, '<em>$1</em>')
                // Strikethrough ~text~
                .replace(/~([^~]+)~/g, '<del>$1</del>')
                // Code block `text`
                .replace(/`([^`]+)`/g, '<code class="bg-gray-800 text-green-400 px-1 rounded">$1</code>')
                // Line breaks
                .replace(/\n/g, '<br/>');

            return formatted;
        }

        // --- Canned Autocompletes & Editor Key Event ---

        onEditorInput(e) {
            const val = $(e.currentTarget).val();
            const $dropdown = this.$('#canned-autocomplete-dropdown');

            if (val.startsWith('/')) {
                const search = val.substring(1).toLowerCase();
                const filtered = this.cannedResponses.filter(r => r.shortcut.toLowerCase().includes(search));

                if (filtered.length > 0) {
                    $dropdown.empty().show();
                    filtered.forEach((item) => {
                        $dropdown.append(`
                            <div class="canned-item" data-content="${item.content}">
                                <strong>${item.shortcut}</strong> - <span class="text-xs text-gray-400">${item.content.substring(0, 30)}...</span>
                            </div>
                        `);
                    });
                } else {
                    $dropdown.hide();
                }
            } else {
                $dropdown.hide();
            }
        }

        onCannedSelect(e) {
            const content = $(e.currentTarget).data('content');
            this.$('#chat-editor').val(content).focus();
            this.$('#canned-autocomplete-dropdown').hide();
        }

        onEditorKeyPress(e) {
            if (e.which === 13 && !e.shiftKey) { // Enter sends message
                e.preventDefault();
                this.onSendMessage();
            }
        }

        onToggleInternalNote() {
            this.isInternalNoteActive = !this.isInternalNoteActive;
            const $btn = this.$('#toggle-internal-note');
            const $editor = this.$('#chat-editor');

            if (this.isInternalNoteActive) {
                $btn.addClass('btn-warning-active').html('<i class="fas fa-lock"></i> Private Note ON');
                $editor.addClass('editor-internal-note-active').attr('placeholder', 'Type a private team note...');
            } else {
                $btn.removeClass('btn-warning-active').html('<i class="fas fa-sticky-note"></i> Internal Note');
                $editor.removeClass('editor-internal-note-active').attr('placeholder', 'Type a WhatsApp message...');
            }
        }

        onSendMessage() {
            const text = this.$('#chat-editor').val().trim();
            if (empty(text) || !this.selectedConv) {
                return;
            }

            const payload = {
                conversationId: this.selectedConv.id,
                agentId: this.getUser().id
            };

            const endpoint = this.isInternalNoteActive ? 'WhatsApp/notes' : 'WhatsApp/sendMessage';
            const bodyField = this.isInternalNoteActive ? 'note' : 'content';

            if (this.isInternalNoteActive) {
                payload.note = text;
            } else {
                payload.type = 'text';
                payload.content = { body: text };
            }

            this.getHelper().ajax({
                type: 'POST',
                url: endpoint,
                data: JSON.stringify(payload),
                dataType: 'json',
                contentType: 'application/json'
            }).then((res) => {
                this.$('#chat-editor').val('');
                this.$('#canned-autocomplete-dropdown').hide();
                this.fetchMessages(this.selectedConv.id, true);
                if (this.isInternalNoteActive) {
                    this.onToggleInternalNote(); // Turn off note mode
                }
            }).fail((xhr) => {
                const err = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to send';
                this.getHelper().showToast(err, 'error');
            });
        }

        // --- File Attachment Handler ---

        onTriggerFilePicker() {
            this.$('#media-file-input').click();
        }

        onUploadMediaAttachment(e) {
            const file = e.currentTarget.files[0];
            if (!file || !this.selectedConv) {
                return;
            }

            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => {
                const base64Data = reader.result;

                // Let's call sendMessage api with a media link
                const type = file.type.split('/')[0]; // image, video, audio or application
                const cleanType = ['image', 'video', 'audio'].includes(type) ? type : 'document';

                this.getHelper().ajax({
                    type: 'POST',
                    url: 'WhatsApp/sendMessage',
                    data: JSON.stringify({
                        conversationId: this.selectedConv.id,
                        agentId: this.getUser().id,
                        type: cleanType,
                        content: {
                            link: base64Data, // server will store it securely locally/S3
                            filename: file.name,
                            caption: `Sent file: ${file.name}`
                        }
                    }),
                    dataType: 'json',
                    contentType: 'application/json'
                }).then(() => {
                    this.fetchMessages(this.selectedConv.id, true);
                }).fail((xhr) => {
                    const err = xhr.responseJSON ? xhr.responseJSON.error : 'Media upload failed';
                    this.getHelper().showToast(err, 'error');
                });
            };
        }

        // --- Templates Manager Dropdown & Variables Modal ---

        populateTemplatePicker() {
            const $picker = this.$('#template-select-picker');
            $picker.empty().append('<option value="">-- Choose Template --</option>');
            
            this.templates.forEach((tmpl) => {
                $picker.append(`<option value="${tmpl.id}">${tmpl.name} (${tmpl.language})</option>`);
            });
        }

        onShowTemplateSelector() {
            this.$('#template-modal-container').fadeIn(200);
        }

        onTemplatePickerChange(e) {
            const id = $(e.currentTarget).val();
            const $vars = this.$('#template-variables-inputs');
            $vars.empty();

            if (empty(id)) {
                return;
            }

            const tmpl = this.templates.find(t => t.id === id);
            const components = JSON.parse(tmpl.components) || [];
            
            // Check Body text for placeholders {{1}}, {{2}}
            components.forEach((comp) => {
                if (comp.type.toLowerCase() === 'body') {
                    const text = comp.text || '';
                    const matches = text.match(/\{\{(\d+)\}\}/g);
                    if (matches) {
                        $vars.append('<label class="block text-sm font-semibold mb-2">Configure Merge placeholders:</label>');
                        matches.forEach((placeholder, idx) => {
                            $vars.append(`
                                <div class="mb-2">
                                    <label class="text-xs text-gray-400">Placeholder ${placeholder} value:</label>
                                    <input type="text" class="form-control mt-1 template-variable-field" data-index="${idx + 1}" placeholder="Enter value for ${placeholder}"/>
                                </div>
                            `);
                        });
                    } else {
                        $vars.append('<p class="text-sm text-gray-400">This template has no placeholder fields.</p>');
                    }
                }
            });
        }

        onSendTemplateMessage() {
            const id = this.$('#template-select-picker').val();
            if (empty(id) || !this.selectedConv) {
                return;
            }

            const tmpl = this.templates.find(t => t.id === id);
            const variables = [];
            
            this.$('.template-variable-field').each(function() {
                variables.push({
                    type: 'text',
                    text: $(this).val() || ''
                });
            });

            const components = [];
            if (variables.length > 0) {
                components.push({
                    type: 'body',
                    parameters: variables
                });
            }

            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/sendMessage',
                data: JSON.stringify({
                    conversationId: this.selectedConv.id,
                    agentId: this.getUser().id,
                    type: 'template',
                    content: {
                        name: tmpl.name,
                        language_code: tmpl.language,
                        components: components
                    }
                }),
                dataType: 'json',
                contentType: 'application/json'
            }).then(() => {
                this.$('#template-modal-container').fadeOut(200);
                this.fetchMessages(this.selectedConv.id, true);
            }).fail((xhr) => {
                const err = xhr.responseJSON ? xhr.responseJSON.error : 'Failed to send template';
                this.getHelper().showToast(err, 'error');
            });
        }

        // --- Reassign & Re-open ---

        onResolveConversation() {
            this.updateConversationAssignState({
                conversationId: this.selectedConv.id,
                status: 'Resolved'
            });
        }

        onReopenConversation() {
            this.updateConversationAssignState({
                conversationId: this.selectedConv.id,
                status: 'Open'
            });
        }

        onReassignOwner(e) {
            const userId = $(e.currentTarget).val();
            this.updateConversationAssignState({
                conversationId: this.selectedConv.id,
                assignedUserId: userId
            });
        }

        updateConversationAssignState(payload) {
            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/assign',
                data: JSON.stringify(payload),
                dataType: 'json',
                contentType: 'application/json'
            }).then(() => {
                this.fetchConversations(true);
                if (this.selectedConv) {
                    this.onConversationSelect({ currentTarget: this.$(`.chat-item[data-id="${this.selectedConv.id}"]`)[0] });
                }
            });
        }

        // --- Canned shortcuts CRUD Manager ---

        onShowCannedManager() {
            this.$('#canned-modal-container').fadeIn(200);
        }

        renderCannedList() {
            const $list = this.$('#canned-responses-list');
            $list.empty();

            if (this.cannedResponses.length === 0) {
                $list.append('<p class="text-sm text-gray-400">No canned shortcuts yet. Create one above.</p>');
                return;
            }

            this.cannedResponses.forEach((item) => {
                $list.append(`
                    <div class="canned-list-item bg-gray-950 p-2 rounded mb-2 border border-gray-800 flex justify-between items-center">
                        <div>
                            <strong class="text-green-400 font-mono">${item.shortcut}</strong>
                            <div class="text-xs text-gray-400 mt-1">${item.content}</div>
                        </div>
                    </div>
                `);
            });
        }

        onSaveCannedResponse(e) {
            e.preventDefault();
            const shortcut = this.$('#canned-shortcut-input').val().trim();
            const content = this.$('#canned-content-input').val().trim();

            if (empty(shortcut) || empty(content)) {
                return;
            }

            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/cannedResponses',
                data: JSON.stringify({ shortcut, content }),
                dataType: 'json',
                contentType: 'application/json'
            }).then(() => {
                this.$('#canned-shortcut-input').val('');
                this.$('#canned-content-input').val('');
                this.fetchCannedResponses();
            });
        }

        // --- Templates Manager view ---

        renderTemplates() {
            const $container = this.$('#templates-container');
            $container.empty();

            if (this.templates.length === 0) {
                $container.html('<div class="empty-list-notice">No synced templates found. Press "Sync templates" to load from Meta.</div>');
                return;
            }

            this.templates.forEach((tmpl) => {
                const categoryClass = tmpl.category === 'Marketing' ? 'badge-marketing' : (tmpl.category === 'Utility' ? 'badge-utility' : 'badge-auth');
                
                $container.append(`
                    <div class="template-card animate__animated animate__fadeIn">
                        <div class="flex justify-between items-center mb-3">
                            <span class="template-name-lbl">${tmpl.name}</span>
                            <span class="template-badge ${categoryClass}">${tmpl.category}</span>
                        </div>
                        <div class="text-xs text-gray-400 font-semibold mb-2">Language: <span class="text-white">${tmpl.language}</span></div>
                        <div class="template-preview-box">
                            ${this.renderTemplatePreview(tmpl.components)}
                        </div>
                    </div>
                `);
            });
        }

        renderTemplatePreview(componentsJson) {
            const comps = JSON.parse(componentsJson) || [];
            let preview = '';

            comps.forEach((c) => {
                if (c.type.toLowerCase() === 'header' && c.format === 'TEXT') {
                    preview += `<div class="preview-header font-bold text-sm mb-1">${c.text}</div>`;
                } else if (c.type.toLowerCase() === 'body') {
                    preview += `<div class="preview-body text-xs leading-relaxed text-gray-300 mb-2">${c.text}</div>`;
                } else if (c.type.toLowerCase() === 'buttons') {
                    preview += '<div class="preview-buttons flex gap-1 mt-2">';
                    (c.buttons || []).forEach((b) => {
                        preview += `<span class="preview-btn-item bg-gray-800 text-xs px-2 py-1 rounded text-center border border-gray-700 flex-1">${b.text}</span>`;
                    });
                    preview += '</div>';
                }
            });

            return preview || 'Empty template preview';
        }

        onSyncTemplates() {
            const $btn = this.$('#sync-templates-btn');
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Syncing...').attr('disabled', true);

            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/syncTemplates'
            }).then((res) => {
                $btn.html('<i class="fas fa-sync-alt"></i> Sync Approved Templates').attr('disabled', false);
                this.getHelper().showToast(`Successfully synced ${res.count} templates!`, 'success');
                this.fetchTemplates();
            }).fail((xhr) => {
                $btn.html('<i class="fas fa-sync-alt"></i> Sync Approved Templates').attr('disabled', false);
                const err = xhr.responseJSON ? xhr.responseJSON.error : 'Sync failed';
                this.getHelper().showToast(err, 'error');
            });
        }

        // --- Campaigns Broadcast launcher ---

        renderCampaigns() {
            const $list = this.$('#campaigns-list-container');
            $list.empty();

            if (this.campaigns.length === 0) {
                $list.html('<div class="empty-list-notice">No broadcasts sent yet.</div>');
                return;
            }

            this.campaigns.forEach((camp) => {
                const total = camp.totalCount || 0;
                const sent = camp.sentCount || 0;
                const delivered = camp.deliveredCount || 0;
                const read = camp.readCount || 0;
                const failed = camp.failedCount || 0;

                const progress = total > 0 ? round((sent / total) * 100, 0) : 0;
                const readPercent = sent > 0 ? round((read / sent) * 100, 0) : 0;

                const statusColor = camp.status === 'Completed' ? 'text-green-400' : 'text-yellow-400';

                $list.append(`
                    <div class="campaign-row bg-gray-900 border border-gray-800 p-4 rounded mb-3 animate__animated animate__fadeInUp">
                        <div class="flex justify-between items-center mb-3">
                            <div>
                                <span class="campaign-title font-semibold text-lg">${camp.name}</span>
                                <span class="text-xs block text-gray-400 mt-1">Template: <strong>${camp.templateName || 'WhatsApp template'}</strong></span>
                            </div>
                            <span class="font-bold text-xs uppercase ${statusColor}">${camp.status}</span>
                        </div>
                        
                        <div class="mb-3">
                            <div class="flex justify-between text-xs text-gray-400 mb-1">
                                <span>Progress: ${sent}/${total} Sent</span>
                                <span>${progress}%</span>
                            </div>
                            <div class="w-full bg-gray-850 h-2 rounded-full overflow-hidden">
                                <div class="bg-green-500 h-full rounded-full" style="width: ${progress}%"></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-4 gap-2 text-center text-xs">
                            <div class="bg-gray-950 p-2 rounded">
                                <span class="block text-gray-400">Delivered</span>
                                <strong class="text-white text-sm">${delivered}</strong>
                            </div>
                            <div class="bg-gray-950 p-2 rounded">
                                <span class="block text-gray-400">Read (CTR)</span>
                                <strong class="text-blue-400 text-sm">${read} (${readPercent}%)</strong>
                            </div>
                            <div class="bg-gray-950 p-2 rounded">
                                <span class="block text-red-400">Failed</span>
                                <strong class="text-red-400 text-sm">${failed}</strong>
                            </div>
                            <div class="bg-gray-950 p-2 rounded flex flex-col justify-center">
                                <span class="text-gray-400">Limits</span>
                                <span class="font-semibold text-white">${camp.rateLimit}/min</span>
                            </div>
                        </div>
                    </div>
                `);
            });
        }

        onShowCampaignForm() {
            const $form = this.$('#campaign-form-container');
            $form.slideToggle(200);

            // Populate templates list
            const $picker = this.$('#campaign-template-select');
            $picker.empty();
            this.templates.forEach((tmpl) => {
                $picker.append(`<option value="${tmpl.id}">${tmpl.name}</option>`);
            });
        }

        onLaunchCampaign(e) {
            e.preventDefault();
            const name = this.$('#campaign-name').val().trim();
            const templateId = this.$('#campaign-template-select').val();
            const segmentType = this.$('#campaign-segment-select').val();
            const city = this.$('#campaign-filter-city').val().trim();
            const leadSource = this.$('#campaign-filter-source').val();
            const status = this.$('#campaign-filter-status').val();

            if (empty(name) || empty(templateId)) {
                return;
            }

            this.$('#launch-campaign-btn').html('<i class="fas fa-spinner fa-spin"></i> Launching...').attr('disabled', true);

            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/sendCampaign',
                data: JSON.stringify({
                    name,
                    templateId,
                    segmentType,
                    filters: {
                        city,
                        leadSource,
                        status
                    }
                }),
                dataType: 'json',
                contentType: 'application/json'
            }).then((res) => {
                this.$('#campaign-name').val('');
                this.$('#campaign-filter-city').val('');
                this.$('#campaign-form-container').slideUp(200);
                this.$('#launch-campaign-btn').html('<i class="fas fa-rocket"></i> Launch Broadcast Campaign').attr('disabled', false);
                this.getHelper().showToast(`Successfully launched broadcast to ${res.totalCount} recipients!`, 'success');
                this.fetchCampaigns();
            }).fail((xhr) => {
                this.$('#launch-campaign-btn').html('<i class="fas fa-rocket"></i> Launch Broadcast Campaign').attr('disabled', false);
                const err = xhr.responseJSON ? xhr.responseJSON.error : 'Launch failed';
                this.getHelper().showToast(err, 'error');
            });
        }

        // --- Analytics Widgets ---

        renderAnalytics() {
            this.getHelper().ajax({
                type: 'GET',
                url: 'WhatsApp/analytics'
            }).then((data) => {
                this.$('#analytic-messages-today').text(data.totalMessagesToday);
                this.$('#analytic-active-agents').text(data.activeAgents);
                this.$('#analytic-open-chats').text(data.openConversations);
                this.$('#analytic-pending-chats').text(data.pendingConversations);
                
                this.$('#analytic-delivery-rate').text(data.deliveryRate + '%');
                this.$('#analytic-read-rate').text(data.readRate + '%');
                this.$('#analytic-failed-count').text(data.failedMessages);

                // Add graphical bars to widgets
                this.$('#delivery-rate-bar').css('width', data.deliveryRate + '%');
                this.$('#read-rate-bar').css('width', data.readRate + '%');
            });
        }

        // --- Settings Management ---

        renderSettings() {
            if (!this.settings) {
                return;
            }

            this.$('#settings-app-id').val(this.settings.metaAppId);
            this.$('#settings-app-secret').val(this.settings.metaAppSecret);
            this.$('#settings-permanent-token').val(this.settings.metaPermanentToken);
            this.$('#settings-waba-id').val(this.settings.metaWabaId);
            this.$('#settings-phone-id').val(this.settings.metaPhoneNumberId);
            this.$('#settings-business-name').val(this.settings.metaBusinessName);

            // Routing
            this.$('#settings-routing-select').val(this.settings.routingRule);
            this.$('#settings-autocreate-lead').prop('checked', this.settings.autoCreateLead);
            this.$('#settings-block-agents').prop('checked', this.settings.blockOtherAgents);

            // S3 Storage
            this.$('#settings-s3-enabled').prop('checked', this.settings.s3Enabled);
            this.$('#settings-s3-bucket').val(this.settings.s3Bucket);
            this.$('#settings-s3-region').val(this.settings.s3Region);
            this.$('#settings-s3-access-key').val(this.settings.s3AccessKey);
            this.$('#settings-s3-secret-key').val(this.settings.s3SecretKey);

            // Expose read-only webhook
            this.$('#settings-webhook-url-display').val(this.settings.webhookUrl);
            this.$('#settings-webhook-token-display').val(this.settings.webhookToken);
        }

        onSubmitSettings(e) {
            e.preventDefault();

            const payload = {
                metaAppId: this.$('#settings-app-id').val().trim(),
                metaAppSecret: this.$('#settings-app-secret').val().trim(),
                metaPermanentToken: this.$('#settings-permanent-token').val().trim(),
                metaWabaId: this.$('#settings-waba-id').val().trim(),
                metaPhoneNumberId: this.$('#settings-phone-id').val().trim(),
                metaBusinessName: this.$('#settings-business-name').val().trim(),
                routingRule: this.$('#settings-routing-select').val(),
                autoCreateLead: this.$('#settings-autocreate-lead').is(':checked'),
                blockOtherAgents: this.$('#settings-block-agents').is(':checked'),
                s3Enabled: this.$('#settings-s3-enabled').is(':checked'),
                s3Bucket: this.$('#settings-s3-bucket').val().trim(),
                s3Region: this.$('#settings-s3-region').val().trim(),
                s3AccessKey: this.$('#settings-s3-access-key').val().trim(),
                s3SecretKey: this.$('#settings-s3-secret-key').val().trim()
            };

            this.getHelper().ajax({
                type: 'POST',
                url: 'WhatsApp/settings',
                data: JSON.stringify(payload),
                dataType: 'json',
                contentType: 'application/json'
            }).then(() => {
                this.getHelper().showToast('Settings saved successfully!', 'success');
                this.fetchSettings();
            });
        }

        onCopyWebhookUrl() {
            const url = this.$('#settings-webhook-url-display').val();
            navigator.clipboard.writeText(url).then(() => {
                this.getHelper().showToast('Webhook URL copied!', 'success');
            });
        }

        onCopyWebhookToken() {
            const token = this.$('#settings-webhook-token-display').val();
            navigator.clipboard.writeText(token).then(() => {
                this.getHelper().showToast('Verify Token copied!', 'success');
            });
        }

        fetchLogs() {
            const status = this.$('#logs-status-filter').val() || 'all';
            const search = this.$('#logs-search').val() || '';

            this.getHelper().ajax({
                type: 'GET',
                url: `WhatsApp/logs?status=${status}&search=${encodeURIComponent(search)}`
            }).then((data) => {
                this.logs = data;
                this.renderLogs();
            });
        }

        renderLogs() {
            const $list = this.$('#logs-list-container');
            $list.empty();

            if (this.logs.length === 0) {
                $list.html('<div class="empty-list-notice">No webhook or diagnostics logs found.</div>');
                return;
            }

            this.logs.forEach((log) => {
                const badgeClass = log.status === 'Success' ? 'badge-success' : 'badge-danger';
                const errorSection = log.errorMessage ? `<div class="text-xs text-red-400 mt-2 font-mono"><strong class="text-red-500">Error:</strong> ${log.errorMessage}</div>` : '';
                
                $list.append(`
                    <div class="bg-gray-950 p-4 rounded border border-gray-800 animate__animated animate__fadeInUp mb-3">
                        <div class="flex justify-between items-center mb-2">
                            <div class="flex items-center gap-2">
                                <span class="badge ${badgeClass} font-mono">${log.status}</span>
                                <span class="text-xs text-gray-400 font-semibold">Event: <span class="text-white">${log.eventCategory || 'Webhook'}</span></span>
                            </div>
                            <span class="text-xs text-gray-400 font-mono">${new Date(log.createdAt).toLocaleString()}</span>
                        </div>
                        <div class="text-xs text-gray-300 font-mono bg-gray-900 p-2 rounded overflow-x-auto max-h-[120px] whitespace-pre-wrap">${log.payloadSnippet}</div>
                        ${errorSection}
                    </div>
                `);
            });
        }
    };
});
