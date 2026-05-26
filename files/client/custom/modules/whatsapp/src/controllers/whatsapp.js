define(['controller'], (Controller) => {
    return class extends Controller {
        actionIndex(options) {
            // Keep active navigation tab highlighted
            this.getNavigation().select('WhatsApp');

            // Render our Unified Omnichannel Workspace View in the main layout container
            this.createView('main', 'whatsapp:views/whatsapp', {
                el: '#main'
            }, (view) => {
                view.render();
            });
        }
    };
});
