/**
 * Bouton « afficher / masquer » sur un champ mot de passe.
 *
 * Le champ reste de type `password` par défaut : l'affichage en clair est une
 * action volontaire de l'utilisateur, jamais l'état initial.
 */
export function initPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            return;
        }

        const icon = button.querySelector('i');

        button.addEventListener('click', () => {
            const reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';

            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute(
                'aria-label',
                reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe',
            );

            if (icon) {
                icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
            }

            // Le changement de type replace le curseur au début : on le remet
            // en fin de saisie pour que l'utilisateur puisse continuer à taper.
            input.focus();

            try {
                const end = input.value.length;
                input.setSelectionRange(end, end);
            } catch {
                // setSelectionRange n'est pas disponible sur tous les types de
                // champs selon le navigateur : sans importance ici.
            }
        });
    });
}
