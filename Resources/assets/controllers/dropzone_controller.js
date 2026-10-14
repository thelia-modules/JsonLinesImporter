import { Controller } from '@hotwired/stimulus';

// Drag and drop zone of the upload form: the dropped file goes into the file input, so the form
// posts it as if it had been chosen. Only .jsonl files enable the submit button.
export default class extends Controller {
    static targets = ['zone', 'input', 'label', 'error', 'submit'];

    connect() {
        this.dragOver = this.dragOver.bind(this);
        this.dragLeave = this.dragLeave.bind(this);
        this.drop = this.drop.bind(this);

        this.zoneTarget.addEventListener('dragover', this.dragOver);
        this.zoneTarget.addEventListener('dragleave', this.dragLeave);
        this.zoneTarget.addEventListener('drop', this.drop);
    }

    disconnect() {
        this.zoneTarget.removeEventListener('dragover', this.dragOver);
        this.zoneTarget.removeEventListener('dragleave', this.dragLeave);
        this.zoneTarget.removeEventListener('drop', this.drop);
    }

    dragOver(event) {
        event.preventDefault();
        this.zoneTarget.classList.add('jli-dropzone--over');
    }

    dragLeave() {
        this.zoneTarget.classList.remove('jli-dropzone--over');
    }

    drop(event) {
        event.preventDefault();
        this.zoneTarget.classList.remove('jli-dropzone--over');

        if (!event.dataTransfer || event.dataTransfer.files.length === 0) {
            return;
        }

        const transfer = new DataTransfer();
        transfer.items.add(event.dataTransfer.files[0]);
        this.inputTarget.files = transfer.files;
        this.select();
    }

    select() {
        const file = this.inputTarget.files[0];
        const isJsonLines = file !== undefined && /\.jsonl$/i.test(file.name);

        this.errorTarget.classList.toggle('d-none', file === undefined || isJsonLines);
        this.submitTarget.disabled = !isJsonLines;
        this.zoneTarget.classList.toggle('jli-dropzone--ready', isJsonLines);

        if (file !== undefined) {
            this.labelTarget.textContent = file.name;
        }
    }
}
