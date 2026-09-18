/**
 * Assainit du HTML avant de l'injecter dans la page.
 *
 * Le contenu vient d'un site WordPress tiers : même si `innerHTML` n'exécute
 * pas les balises `<script>`, un `onerror` sur une image, lui, s'exécute. On
 * retire donc tout ce qui est exécutable avant affichage.
 *
 * Le HTML d'origine n'est pas modifié : il reste dans l'onglet « Code source »
 * et c'est lui qui repart vers WordPress tant que l'utilisateur ne l'édite pas
 * visuellement.
 */

const FORBIDDEN_TAGS = ['script', 'style', 'object', 'embed', 'applet', 'link', 'meta', 'base', 'form'];
const URL_ATTRIBUTES = ['href', 'src', 'srcdoc', 'xlink:href', 'action', 'formaction'];
const DANGEROUS_PROTOCOLS = /^\s*(javascript|vbscript|data:text\/html)/i;

export function sanitizeHtml(html) {
    const doc = new DOMParser().parseFromString(`<div id="ag-sanitize-root">${html}</div>`, 'text/html');
    const root = doc.getElementById('ag-sanitize-root');

    root.querySelectorAll(FORBIDDEN_TAGS.join(',')).forEach((node) => node.remove());

    root.querySelectorAll('*').forEach((node) => {
        Array.from(node.attributes).forEach((attribute) => {
            const name = attribute.name.toLowerCase();

            if (name.startsWith('on')) {
                node.removeAttribute(attribute.name);
                return;
            }

            if (URL_ATTRIBUTES.includes(name) && DANGEROUS_PROTOCOLS.test(attribute.value)) {
                node.removeAttribute(attribute.name);
            }
        });
    });

    return root.innerHTML;
}
