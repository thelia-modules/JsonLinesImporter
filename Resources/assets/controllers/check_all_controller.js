import { Controller } from '@hotwired/stimulus';

// "All / none" button of a group of checkboxes.
export default class extends Controller {
    static targets = ['box'];

    toggle() {
        const enabled = this.boxTargets.filter((box) => !box.disabled);
        const allChecked = enabled.every((box) => box.checked);

        enabled.forEach((box) => {
            box.checked = !allChecked;
        });
    }
}
