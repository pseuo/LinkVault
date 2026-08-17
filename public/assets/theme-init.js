(() => {
    let theme = 'light';
    let capabilityMode = 'basic';
    try {
        const savedTheme = localStorage.getItem('linkvault-theme');
        theme = ['light', 'dark'].includes(savedTheme)
            ? savedTheme
            : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        capabilityMode = localStorage.getItem('linkvault-capability-mode') === 'advanced' ? 'advanced' : 'basic';
    } catch (error) {
        try {
            theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        } catch (mediaError) {
        }
    }
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.capabilityMode = capabilityMode;
})();
