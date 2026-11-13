document.addEventListener('click', (event) => {
    const print = event.target.closest('[data-print]');
    if (print) window.print();
    const add = event.target.closest('[data-po-add]');
    if (!add) return;
    const field = document.querySelector('#po-line-template');
    const target = document.querySelector('#po-lines');
    const index = target.querySelectorAll('fieldset').length;
    if (index >= 30) return;
    const next = field.content.cloneNode(true);
    next.querySelectorAll('[data-name]').forEach((input) => {
        input.name = `items[${index}][${input.dataset.name}]`;
    });
    target.append(next);
});

document.addEventListener('submit', (event) => {
    if (event.submitter?.matches('[data-remove-line]')) {
        event.target.elements.quantity.value = 0;
    }
});
