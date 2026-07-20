// Dark/light theme toggle. Persists to localStorage['app-theme'] (default: dark)
// and reflects the choice on <html data-theme>. A tiny inline script in the layout
// sets the initial attribute before paint to avoid a flash.

const STORAGE_KEY = 'app-theme';

export function currentTheme() {
    return document.documentElement.dataset.theme || 'dark';
}

export function setTheme(theme) {
    const value = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = value;
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        /* ignore storage errors (private mode, etc.) */
    }
    document.querySelectorAll('[data-theme-choice]').forEach((btn) => {
        btn.setAttribute('aria-pressed', String(btn.dataset.themeChoice === value));
    });
}

export function initTheme() {
    // Reflect the already-applied theme onto the controls, then wire clicks.
    setTheme(currentTheme());
    document.querySelectorAll('[data-theme-choice]').forEach((btn) => {
        btn.addEventListener('click', () => setTheme(btn.dataset.themeChoice));
    });
}

document.addEventListener('DOMContentLoaded', initTheme);
