document.addEventListener('DOMContentLoaded', () => {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabSections = document.querySelectorAll('.tab-section');
    const legacyCheckbox = document.getElementById('legacy-mode');
    const legacyUpload = document.getElementById('legacy-upload');
    const darkModeToggle = document.getElementById('dark-mode');

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');
            tabButtons.forEach((btn) => btn.classList.remove('active'));
            tabSections.forEach((section) => section.classList.remove('active'));
            button.classList.add('active');
            document.getElementById(`tab-${target}`).classList.add('active');
        });
    });

    if (legacyCheckbox && legacyUpload) {
        const toggleLegacyUpload = () => {
            legacyUpload.hidden = !legacyCheckbox.checked;
        };
        legacyCheckbox.addEventListener('change', toggleLegacyUpload);
        toggleLegacyUpload();
    }

    if (darkModeToggle) {
        darkModeToggle.addEventListener('change', () => {
            document.body.classList.toggle('light-mode');
        });
    }
});
