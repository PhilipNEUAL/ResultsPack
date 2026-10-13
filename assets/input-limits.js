(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('resultspack-generator');
        const config = document.getElementById('resultspack-text-limits');
        if (!form || !config) return;

        let limits;
        try { limits = JSON.parse(config.value); }
        catch (error) { return; }

        const ruleFor = (name) => {
            for (const [pattern, rule] of Object.entries(limits)) {
                if (name === pattern || (
                    pattern.endsWith('*') && name.startsWith(pattern.slice(0, -1))
                )) return rule;
            }
            return null;
        };

        const apply = (field) => {
            const rule = ruleFor(field.name || '');
            if (!rule || !field.matches('input[type="text"], input[type="email"], textarea')) return;

            field.maxLength = rule[0];
            const update = () => {
                const tooLong = field.value.length > rule[0];
                field.setCustomValidity(tooLong
                    ? `${rule[1]} exceeds its ${rule[0]}-character limit. Shorten it or reset this form.`
                    : '');
                const count = field.parentElement.querySelector(
                    `[data-limit-for="${field.name}"]`
                );
                if (count) {
                    count.textContent = `${field.value.length} / ${rule[0]} characters`;
                    count.style.color = tooLong ? '#a12622' : '#666';
                }
            };

            if (!field.dataset.textLimitInstalled) {
                field.dataset.textLimitInstalled = 'yes';
                const count = document.createElement('small');
                count.dataset.limitFor = field.name;
                count.style.cssText = 'display:block;margin-top:3px;';
                field.insertAdjacentElement('afterend', count);
                field.addEventListener('input', update);
                field.addEventListener('change', update);
            }
            update();
        };

        Array.from(form.elements).forEach(apply);

        form.addEventListener('change', () => Array.from(form.elements).forEach(apply));
        form.addEventListener('submit', (event) => {
            Array.from(form.elements).forEach(apply);
            if (!form.reportValidity()) event.preventDefault();
        }, true);

        new MutationObserver((records) => {
            records.forEach((record) => record.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('input, textarea')) apply(node);
                node.querySelectorAll('input, textarea').forEach(apply);
            }));
        }).observe(form, { childList: true, subtree: true });
    });
})();
