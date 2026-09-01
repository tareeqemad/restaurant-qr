/**
 * Classic form POST with full-page navigation.
 *
 * The shell posts to endpoints that answer with a redirect-back (branch
 * switch, clock in/out, logout) and mostly land on pages that are still
 * Blade. An Inertia/XHR visit would choke on the non-Inertia response,
 * so we do exactly what the Blade chrome did: a real <form> submit.
 */
export function formPost(url, fields = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.style.display = 'none';

    const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    add('_token', token);
    Object.entries(fields).forEach(([name, value]) => add(name, value));

    document.body.appendChild(form);
    form.submit();
}
