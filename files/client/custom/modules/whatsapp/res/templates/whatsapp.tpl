<div class="whatsapp-workspace-container animate__animated animate__fadeIn">
    
    <!-- LEFT NAVIGATION MENUS -->
    <div class="whatsapp-navigation-sidebar">
        <div class="brand-section">
            <i class="fab fa-whatsapp brand-icon"></i>
            <span class="brand-name">Omnichannel</span>
        </div>
        <ul class="nav-menu-list">
            <li class="nav-menu-item" data-tab="inbox">
                <i class="fas fa-comments"></i> <span>Inbox</span>
            </li>
            <li class="nav-menu-item" data-tab="templates">
                <i class="fas fa-file-invoice"></i> <span>Templates</span>
            </li>
            <li class="nav-menu-item" data-tab="campaigns">
                <i class="fas fa-bullhorn"></i> <span>Broadcasts</span>
            </li>
            <li class="nav-menu-item" data-tab="analytics">
                <i class="fas fa-chart-pie"></i> <span>Analytics</span>
            </li>
            <li class="nav-menu-item" data-tab="settings">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </li>
            <li class="nav-menu-item" data-tab="logs">
                <i class="fas fa-file-alt"></i> <span>Logs</span>
            </li>
            <li class="nav-menu-item" data-tab="logs">
                <i class="fas fa-file-alt"></i> <span>Logs</span>
            </li>
        </ul>
        <div class="canned-quick-bar text-center mt-auto p-3">
            <button id="canned-manager-btn" class="btn btn-block btn-glass btn-sm">
                <i class="fas fa-keyboard"></i> Quick Replies
            </button>
        </div>
    </div>

    <!-- MAIN INTERACTIVE PANES CONTAINER -->
    <div class="whatsapp-content-pane">
        
        <!-- PANE 1: CHAT INBOX -->
        <div id="pane-inbox" class="workspace-pane h-full">
            <div class="flex h-full">
                <!-- INBOX CHATS LIST SIDEBAR -->
                <div class="inbox-chats-sidebar">
                    <div class="chats-sidebar-header">
                        <div class="flex gap-2 mb-3">
                            <input type="text" id="inbox-search" class="form-control" placeholder="Search chats..."/>
                            <select id="inbox-filter-select" class="form-control max-w-[120px]">
                                <option value="all">All Chats</option>
                                <option value="unread">Unread</option>
                                <option value="assigned">Assigned</option>
                                <option value="pending">Pending</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                    </div>
                    <div id="conversation-list-container" class="chats-list-scrollable">
                        <!-- Dynamic items -->
                    </div>
                </div>

                <!-- CHAT CONVERSATION FEED -->
                <div class="chat-workspace-pane flex-1 flex flex-col relative">
                    
                    <!-- PLACEHOLDER IF NO CHAT SELECTED -->
                    <div id="chat-workspace-placeholder" class="flex-1 flex flex-col justify-center items-center text-center p-8 bg-gray-950/20">
                        <i class="fab fa-whatsapp text-6xl text-gray-700 mb-4 animate__pulse animate__infinite"></i>
                        <h3 class="text-xl font-semibold mb-2">No Active Conversation</h3>
                        <p class="text-gray-400 max-w-[320px] text-sm">Select a customer from the left conversation index to start omnichannel messaging.</p>
                    </div>

                    <!-- ACTIVE CHAT VIEWPORT -->
                    <div id="chat-workspace-active" class="flex-1 flex flex-col h-full hidden">
                        <!-- HEADER BAR -->
                        <div class="chat-active-header">
                            <div class="flex justify-between items-center w-full">
                                <div>
                                    <h4 id="active-chat-title" class="font-bold text-lg mb-1">Customer</h4>
                                    <span id="active-chat-phone" class="text-xs text-gray-400 font-mono">+1234567890</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div id="chat-crm-link"></div>
                                    
                                    <!-- Reassign Select -->
                                    <select id="reassign-select" class="form-control form-control-sm max-w-[140px]" title="Assign Agent">
                                        <option value="">-- Unassigned --</option>
                                        <!-- Will load agents in setup if needed, or simple direct options -->
                                        <option value="admin">System Admin</option>
                                        <option value="agent">Agent Workspace</option>
                                    </select>

                                    <button id="resolve-btn" class="btn btn-sm btn-success flex items-center gap-1">
                                        <i class="fas fa-check-circle"></i> Resolve
                                    </button>
                                    <button id="reopen-btn" class="btn btn-sm btn-warning flex items-center gap-1 hidden">
                                        <i class="fas fa-undo"></i> Reopen
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- MESSAGES BUBBLE STREAM -->
                        <div id="chat-messages-stream" class="chat-message-feed">
                            <!-- Dynamic messages -->
                        </div>

                        <!-- INPUT TEXT AREA / FOOTER -->
                        <div class="chat-active-footer">
                            
                            <!-- AUTOCONTROL DROP PANEL FOR CANNED PHRASES -->
                            <div id="canned-autocomplete-dropdown" class="autocomplete-panel hidden"></div>

                            <div class="flex items-center gap-2 mb-2">
                                <button id="toggle-internal-note" class="btn btn-xs btn-glass text-gray-400">
                                    <i class="fas fa-sticky-note"></i> Internal Note
                                </button>
                                <button id="template-msg-btn" class="btn btn-xs btn-glass text-green-400">
                                    <i class="fas fa-file-invoice"></i> Send Template
                                </button>
                                <button id="media-attach-btn" class="btn btn-xs btn-glass text-blue-400">
                                    <i class="fas fa-paperclip"></i> Media File
                                </button>
                                <input type="file" id="media-file-input" class="hidden" accept="image/*,video/*,audio/*,application/pdf"/>
                            </div>
                            <div class="flex gap-2">
                                <textarea id="chat-editor" class="form-control flex-1 resize-none" rows="2" placeholder="Type a WhatsApp message... (Use '/' for quick replies)"></textarea>
                                <button id="send-msg-btn" class="btn btn-primary px-4"><i class="fas fa-paper-plane text-lg"></i></button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- PANE 2: TEMPLATES -->
        <div id="pane-templates" class="workspace-pane p-6 hidden h-full overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold">WhatsApp Approved Templates</h2>
                    <p class="text-sm text-gray-400">View and manage Meta message templates synced natively.</p>
                </div>
                <button id="sync-templates-btn" class="btn btn-success flex items-center gap-1">
                    <i class="fas fa-sync-alt"></i> Sync Approved Templates
                </button>
            </div>
            <div id="templates-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Dynamic templates -->
            </div>
        </div>

        <!-- PANE 3: CAMPAIGNS BROADCASTS -->
        <div id="pane-campaigns" class="workspace-pane p-6 hidden h-full overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold">Bulk Campaigns & Broadcasting</h2>
                    <p class="text-sm text-gray-400">Launch and analyze targeted bulk broadcast message campaigns.</p>
                </div>
                <button id="create-campaign-btn" class="btn btn-primary flex items-center gap-1">
                    <i class="fas fa-plus"></i> Create Campaign
                </button>
            </div>

            <!-- CAMPAIGN BUILDER FORM OVERLAY -->
            <div id="campaign-form-container" class="bg-gray-900 border border-gray-800 p-6 rounded-lg mb-6 hidden">
                <h4 class="text-lg font-bold mb-4"><i class="fas fa-rocket"></i> Configure Broadcaster Segment</h4>
                <form id="campaign-form">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-semibold mb-2">Campaign Name:</label>
                            <input type="text" id="campaign-name" class="form-control" placeholder="e.g. Summer Promo Sale" required/>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-2">Approved Template:</label>
                            <select id="campaign-template-select" class="form-control" required></select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-2">Target Segment CRM:</label>
                            <select id="campaign-segment-select" class="form-control" required>
                                <option value="Lead">CRM Leads</option>
                                <option value="Contact">CRM Contacts</option>
                            </select>
                        </div>
                    </div>
                    <h5 class="text-sm font-bold mb-3"><i class="fas fa-filter"></i> Apply Smart Filters</h5>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div>
                            <label class="block text-sm mb-1 text-gray-400">Filter by City:</label>
                            <input type="text" id="campaign-filter-city" class="form-control" placeholder="e.g. San Francisco"/>
                        </div>
                        <div>
                            <label class="block text-sm mb-1 text-gray-400">Filter by Lead Source:</label>
                            <select id="campaign-filter-source" class="form-control">
                                <option value="">-- All Sources --</option>
                                <option value="Cold Call">Cold Call</option>
                                <option value="Website">Website</option>
                                <option value="WhatsApp">WhatsApp</option>
                                <option value="Partner">Partner</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm mb-1 text-gray-400">Filter by Status:</label>
                            <select id="campaign-filter-status" class="form-control">
                                <option value="">-- All Statuses --</option>
                                <option value="New">New</option>
                                <option value="Assigned">Assigned</option>
                                <option value="In Process">In Process</option>
                                <option value="Converted">Converted</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" id="launch-campaign-btn" class="btn btn-success"><i class="fas fa-rocket"></i> Launch Broadcast Campaign</button>
                </form>
            </div>

            <div id="campaigns-list-container">
                <!-- Dynamic broadcasts -->
            </div>
        </div>

        <!-- PANE 4: ANALYTICS WIDGETS -->
        <div id="pane-analytics" class="workspace-pane p-6 hidden h-full overflow-y-auto">
            <h2 class="text-2xl font-bold mb-2">WhatsApp Admin Dashboard</h2>
            <p class="text-sm text-gray-400 mb-6">Real-time performance overview, active loads, and delivery ratios.</p>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="analytics-card animate__animated animate__fadeIn">
                    <span class="card-label">Volume Today</span>
                    <strong id="analytic-messages-today" class="card-metric text-white">0</strong>
                </div>
                <div class="analytics-card animate__animated animate__fadeIn">
                    <span class="card-label">Active Agents</span>
                    <strong id="analytic-active-agents" class="card-metric text-green-400">0</strong>
                </div>
                <div class="analytics-card animate__animated animate__fadeIn">
                    <span class="card-label">Open Chats</span>
                    <strong id="analytic-open-chats" class="card-metric text-blue-400">0</strong>
                </div>
                <div class="analytics-card animate__animated animate__fadeIn">
                    <span class="card-label">Pending Queue</span>
                    <strong id="analytic-pending-chats" class="card-metric text-yellow-400">0</strong>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-900 border border-gray-800 p-6 rounded-lg">
                    <h3 class="font-bold text-lg mb-4">Delivery & Read Status Ratios</h3>
                    <div class="mb-4">
                        <div class="flex justify-between text-sm mb-1">
                            <span>Delivery Rate</span>
                            <span id="analytic-delivery-rate" class="font-bold text-green-400">0%</span>
                        </div>
                        <div class="w-full bg-gray-800 h-3 rounded-full overflow-hidden">
                            <div id="delivery-rate-bar" class="bg-green-400 h-full rounded-full" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div class="flex justify-between text-sm mb-1">
                            <span>Read Rate (CTR)</span>
                            <span id="analytic-read-rate" class="font-bold text-blue-400">0%</span>
                        </div>
                        <div class="w-full bg-gray-800 h-3 rounded-full overflow-hidden">
                            <div id="read-rate-bar" class="bg-blue-400 h-full rounded-full" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-900 border border-gray-800 p-6 rounded-lg text-center flex flex-col justify-center items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-3"></i>
                    <h3 class="font-bold text-lg">Delivery Failures</h3>
                    <p class="text-xs text-gray-400 mt-1 max-w-[280px]">Failed template approvals or customer number blocks.</p>
                    <strong id="analytic-failed-count" class="text-red-500 text-5xl mt-3">0</strong>
                </div>
            </div>
        </div>

        <!-- PANE 5: SETTINGS -->
        <div id="pane-settings" class="workspace-pane p-6 hidden h-full overflow-y-auto">
            <h2 class="text-2xl font-bold mb-2">WhatsApp Cloud API Configurations</h2>
            <p class="text-sm text-gray-400 mb-6">Manage Meta API credentials, verify tokens, S3 Cloud buckets, and routing rules.</p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- FORM -->
                <div class="col-span-2 bg-gray-900 border border-gray-800 p-6 rounded-lg">
                    <form id="settings-form">
                        <h4 class="font-bold text-lg mb-4 text-green-400"><i class="fab fa-facebook"></i> Meta WABA Credentials</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-semibold mb-2">Meta App ID:</label>
                                <input type="text" id="settings-app-id" class="form-control" required/>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-2">Meta App Secret:</label>
                                <input type="password" id="settings-app-secret" class="form-control" required/>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-2">Meta Permanent Access Token:</label>
                            <textarea id="settings-permanent-token" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-semibold mb-2">WhatsApp WABA ID:</label>
                                <input type="text" id="settings-waba-id" class="form-control" required/>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-2">Phone Number ID:</label>
                                <input type="text" id="settings-phone-id" class="form-control" required/>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="block text-sm font-semibold mb-2">Business Account Name:</label>
                            <input type="text" id="settings-business-name" class="form-control"/>
                        </div>

                        <h4 class="font-bold text-lg mb-4 text-blue-400"><i class="fas fa-network-wired"></i> Sticky Routing & Assignments</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-semibold mb-2">Routing Protocol:</label>
                                <select id="settings-routing-select" class="form-control">
                                    <option value="StickyAgent">Sticky Agent Ownership</option>
                                    <option value="RoundRobin">Round Robin Assignments</option>
                                    <option value="Queue">Leave In Team Queues</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-1 mt-6">
                                <input type="checkbox" id="settings-autocreate-lead" class="mr-1 mt-1"/>
                                <label class="text-sm font-semibold cursor-pointer" for="settings-autocreate-lead">Auto-create Leads</label>
                            </div>
                            <div class="flex items-center gap-1 mt-6">
                                <input type="checkbox" id="settings-block-agents" class="mr-1 mt-1"/>
                                <label class="text-sm font-semibold cursor-pointer" for="settings-block-agents" title="Blocks other agents from messaging owned contacts">Block Other Agents</label>
                            </div>
                        </div>

                        <h4 class="font-bold text-lg mb-4 text-purple-400"><i class="fas fa-cloud"></i> S3 Storage Settings (Optional)</h4>
                        <div class="flex items-center gap-1 mb-4">
                            <input type="checkbox" id="settings-s3-enabled" class="mr-1 mt-1"/>
                            <label class="text-sm font-semibold cursor-pointer" for="settings-s3-enabled">Enable AWS S3 Cloud Storage</label>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-semibold mb-2">S3 Bucket Name:</label>
                                <input type="text" id="settings-s3-bucket" class="form-control"/>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-2">S3 Region:</label>
                                <input type="text" id="settings-s3-region" class="form-control" placeholder="us-east-1"/>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-semibold mb-2">S3 Access Key:</label>
                                <input type="text" id="settings-s3-access-key" class="form-control"/>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-2">S3 Secret Key:</label>
                                <input type="password" id="settings-s3-secret-key" class="form-control"/>
                            </div>
                        </div>

                        <button type="submit" id="save-settings-btn" class="btn btn-success px-4"><i class="fas fa-save"></i> Save Settings</button>
                    </form>
                </div>

                <!-- WEBHOOK COPY PANELS -->
                <div class="flex flex-col gap-6">
                    <div class="bg-gray-900 border border-gray-800 p-6 rounded-lg">
                        <h4 class="font-bold text-md mb-2 text-green-400"><i class="fas fa-link"></i> Webhook Endpoint URL</h4>
                        <p class="text-xs text-gray-400 mb-3">Copy and paste this URL into the Meta App Webhook configuration panel.</p>
                        <div class="flex gap-2">
                            <input type="text" id="settings-webhook-url-display" class="form-control font-mono text-xs bg-gray-950 border border-gray-800" readonly/>
                            <button id="copy-webhook-url" class="btn btn-sm btn-glass"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="bg-gray-900 border border-gray-800 p-6 rounded-lg">
                        <h4 class="font-bold text-md mb-2 text-blue-400"><i class="fas fa-key"></i> Verify Token</h4>
                        <p class="text-xs text-gray-400 mb-3">Copy this verification token to securely authorize Meta's handshake check.</p>
                        <div class="flex gap-2">
                            <input type="text" id="settings-webhook-token-display" class="form-control font-mono text-xs bg-gray-950 border border-gray-800" readonly/>
                            <button id="copy-webhook-token" class="btn btn-sm btn-glass"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ================= MODALS & POPUPS ================= -->

<!-- 1. TEMPLATE VARIABLES INPUT MODAL -->
<div id="template-modal-container" class="whatsapp-modal-overlay hidden">
    <div class="whatsapp-modal bg-gray-900 border border-gray-800 p-6 rounded-lg max-w-md animate__animated animate__zoomIn">
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-lg font-bold"><i class="fas fa-file-invoice text-green-400"></i> Send Template Message</h4>
            <button onclick="$('#template-modal-container').fadeOut(200)" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-semibold mb-2">Select Template:</label>
            <select id="template-select-picker" class="form-control"></select>
        </div>
        <div id="template-variables-inputs" class="mb-6">
            <!-- Dynamic placeholders inputs -->
        </div>
        <div class="flex justify-end gap-2">
            <button onclick="$('#template-modal-container').fadeOut(200)" class="btn btn-sm btn-glass">Cancel</button>
            <button id="send-template-test-btn" class="btn btn-sm btn-success">Send Message</button>
        </div>
    </div>
</div>

<!-- 2. CANNED SHORTCUTS CRUD MANAGER MODAL -->
<div id="canned-modal-container" class="whatsapp-modal-overlay hidden">
    <div class="whatsapp-modal bg-gray-900 border border-gray-800 p-6 rounded-lg max-w-lg animate__animated animate__zoomIn">
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-lg font-bold"><i class="fas fa-keyboard text-blue-400"></i> Canned Responses Manager</h4>
            <button onclick="$('#canned-modal-container').fadeOut(200)" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
        </div>
        
        <form id="canned-form" class="mb-4 bg-gray-950 p-4 rounded border border-gray-800">
            <h5 class="text-sm font-bold mb-2">Create New Quick Reply:</h5>
            <div class="grid grid-cols-3 gap-2 mb-2">
                <input type="text" id="canned-shortcut-input" class="form-control col-span-1" placeholder="e.g. /welcome" required/>
                <input type="text" id="canned-content-input" class="form-control col-span-2" placeholder="Enter message text..." required/>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Save Shortcut</button>
        </form>

        <h5 class="text-sm font-bold mb-2">Saved Shortcuts:</h5>
        <div id="canned-responses-list" class="max-h-[220px] overflow-y-auto pr-1">
            <!-- Dynamic responses -->
        </div>
    </div>
</div>
