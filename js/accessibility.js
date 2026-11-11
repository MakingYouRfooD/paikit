/**
 * accessibility.js - Senior Citizen & Elderly Accessibility Controls
 * Paikit Health Center Information System
 */
(function() {
    function applySeniorMode(enabled) {
        if (enabled) {
            document.body.classList.add('senior-mode');
            document.documentElement.classList.add('senior-mode');
        } else {
            document.body.classList.remove('senior-mode');
            document.documentElement.classList.remove('senior-mode');
        }
        updateToggleButtons(enabled);
    }

    function updateToggleButtons(enabled) {
        const btns = document.querySelectorAll('.senior-toggle-btn');
        btns.forEach(btn => {
            if (enabled) {
                btn.classList.add('active');
                btn.innerHTML = '<i class="bi bi-fonts fs-6"></i> <strong>A-</strong> Normal Text';
                btn.title = "Switch to Standard Text Size";
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="bi bi-fonts fs-6"></i> <strong>A+</strong> Senior Text Mode';
                btn.title = "Enlarge Text & Buttons for Senior Friendly Reading";
            }
        });
    }

    window.toggleSeniorMode = function() {
        const isCurrent = document.body.classList.contains('senior-mode');
        const newState = !isCurrent;
        localStorage.setItem('paikit_senior_mode', newState ? '1' : '0');
        applySeniorMode(newState);
    };

    // Auto-apply saved preference upon DOM ready
    document.addEventListener('DOMContentLoaded', () => {
        const saved = localStorage.getItem('paikit_senior_mode');
        if (saved === '1') {
            applySeniorMode(true);
        }
    });
})();
