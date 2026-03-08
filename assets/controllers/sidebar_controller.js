import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'sidebar-collapsed';

export default class extends Controller {
    static targets = ['sidebar', 'brand'];

    connect() {
        if (localStorage.getItem(STORAGE_KEY) === '1') {
            this.sidebarTarget.classList.add('sidebar-collapsed');
        }
    }

    toggle() {
        this.sidebarTarget.classList.toggle('sidebar-collapsed');
        const collapsed = this.sidebarTarget.classList.contains('sidebar-collapsed');
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    }
}
