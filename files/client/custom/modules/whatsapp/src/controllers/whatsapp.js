define(['controller'], (Controller) => {
    return class extends Controller {
        actionIndex(options) {
            // Keep active navigation tab highlighted safely
            if (typeof this.getNavigation === 'function') {
                this.getNavigation().select('WhatsApp');
            } else if (this.getHeaderView && typeof this.getHeaderView === 'function') {
                let headerView = this.getHeaderView();
                if (headerView && typeof headerView.selectMenu === 'function') {
                    headerView.selectMenu('WhatsApp');
                }
            }

            // Render our Unified Omnichannel Workspace View in the main layout container
            this.createView('main', 'whatsapp:views/whatsapp', {
                el: '#main'
            }, (view) => {
                view.render();
            });
        }
    };
});
