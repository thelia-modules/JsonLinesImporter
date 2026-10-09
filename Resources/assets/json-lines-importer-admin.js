import { Application } from '@hotwired/stimulus';

import './json-lines-importer-admin.css';

import DropzoneController from './controllers/dropzone_controller.js';
import BatchController from './controllers/batch_controller.js';
import CheckAllController from './controllers/check_all_controller.js';

// Own Stimulus application: the back-office theme builds its controllers with Encore and exposes no
// registry. Both applications scan the same DOM and only act on the identifiers they know.
const application = Application.start();

application.register('jli-dropzone', DropzoneController);
application.register('jli-batch', BatchController);
application.register('jli-check-all', CheckAllController);
