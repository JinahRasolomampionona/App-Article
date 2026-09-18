<?php

namespace Tests\Unit;

use App\Models\WordpressArticle;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditSettings;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;
use App\Services\Audit\Rules\BodyImageRule;
use App\Services\Audit\Rules\FeaturedImageRule;
use App\Services\Audit\Rules\H1Rule;
use App\Services\Audit\Rules\H2Rule;
use App\Services\Audit\Rules\LongTitleRule;
use App\Services\Audit\Rules\ShortcodeRule;
use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chaque règle est testée isolément : elles doivent rester indépendantes les
 * unes des autres.
 */
class AuditRulesTest extends TestCase
{
    // La règle sur l'image à la une consulte le cache d'analyses en base.
    use RefreshDatabase;

    protected function context(array $attributes, array $overrides = []): AuditContext
    {
        $article = new WordpressArticle(array_merge([
            'title' => 'Titre court',
            'content' => '<p>Contenu.</p>',
            'featured_media_id' => 5,
            'featured_media_url' => 'https://example.com/image.jpg',
        ], $attributes));

        $settings = new AuditSettings(
            array_merge(array_map('boolval', (array) config('articleguard.rules')), $overrides),
            (array) config('articleguard.thresholds'),
        );

        // `allowNetwork: false` : aucune règle ne doit déclencher de requête ici.
        return new AuditContext($article, $settings, allowNetwork: false);
    }

    /** @param array<int, Issue> $issues */
    protected function types(array $issues): array
    {
        return array_map(fn (Issue $issue) => $issue->type, $issues);
    }

    /* --- Image à la une ---------------------------------------------------- */

    public function test_image_a_la_une_manquante_est_signalee(): void
    {
        $rule = new FeaturedImageRule(new ImageQualityAnalyzer(new UrlGuard));

        $issues = $rule->evaluate($this->context([
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]));

        $this->assertSame(['featured_image_missing'], $this->types($issues));
        $this->assertSame('Image à la une manquante', $issues[0]->message);
    }

    public function test_image_a_la_une_presente_ne_produit_aucune_remarque(): void
    {
        $rule = new FeaturedImageRule(new ImageQualityAnalyzer(new UrlGuard));

        $this->assertSame([], $rule->evaluate($this->context([])));
    }

    public function test_image_a_la_une_non_resolue_est_signalee(): void
    {
        $rule = new FeaturedImageRule(new ImageQualityAnalyzer(new UrlGuard));

        $issues = $rule->evaluate($this->context([
            'featured_media_id' => 42,
            'featured_media_url' => null,
        ]));

        $this->assertSame(['featured_image_unresolved'], $this->types($issues));
    }

    /* --- Image du contenu -------------------------------------------------- */

    public function test_absence_d_image_dans_le_contenu_est_signalee(): void
    {
        $issues = (new BodyImageRule)->evaluate($this->context([
            'content' => '<p>Un article sans la moindre illustration.</p>',
        ]));

        $this->assertSame(['body_image_missing'], $this->types($issues));
    }

    public function test_une_image_dans_le_contenu_suffit(): void
    {
        $issues = (new BodyImageRule)->evaluate($this->context([
            'content' => '<figure><img src="https://example.com/a.jpg" alt="A"></figure>',
        ]));

        $this->assertSame([], $issues);
    }

    public function test_l_image_a_la_une_ne_compte_pas_comme_image_de_contenu(): void
    {
        // L'article a une image à la une mais aucune image dans son corps.
        $issues = (new BodyImageRule)->evaluate($this->context([
            'content' => '<p>Texte seul.</p>',
            'featured_media_url' => 'https://example.com/featured.jpg',
        ]));

        $this->assertSame(['body_image_missing'], $this->types($issues));
    }

    /* --- Shortcodes -------------------------------------------------------- */

    public function test_un_shortcode_simple_est_detecte(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '<p>Avant [galerie] après.</p>',
        ]));

        $this->assertSame(['shortcode_detected'], $this->types($issues));
        $this->assertSame(['galerie'], $issues[0]->metadata['tags']);
    }

    public function test_un_shortcode_avec_attribut_et_contenu_est_detecte(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '[encadre couleur="bleu"]Texte[/encadre]',
        ]));

        $this->assertSame(['shortcode_detected'], $this->types($issues));
        $this->assertSame(['encadre'], $issues[0]->metadata['tags']);
    }

    /**
     * Les résidus de génération ne respectent pas la syntaxe WordPress :
     * `[public; text...script etc]` doit être signalé au même titre qu'un
     * shortcode valide.
     */
    public function test_un_crochet_hors_syntaxe_shortcode_est_detecte(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '<p>Texte [public; text...script etc] suite.</p>',
        ]));

        $this->assertSame(['shortcode_detected'], $this->types($issues));
        $this->assertStringContainsString('[public; text...script etc]', implode(' ', $issues[0]->metadata['samples']));
    }

    public function test_tout_crochet_du_contenu_est_signale(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '<p>Une liste [1] et [2] de références.</p>',
        ]));

        $this->assertSame(['shortcode_detected'], $this->types($issues));
        $this->assertSame(['[1]', '[2]'], $issues[0]->metadata['samples']);
    }

    public function test_un_crochet_dans_le_titre_est_signale_separement(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'title' => 'Guide des bagues [draft]',
            'content' => '<p>Contenu propre.</p>',
        ]));

        $this->assertSame(['shortcode_detected'], $this->types($issues));
        $this->assertSame('title', $issues[0]->metadata['target']);
        $this->assertSame('Crochet détecté dans le titre', $issues[0]->message);
    }

    public function test_un_contenu_sans_crochet_ne_produit_rien(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '<p>Un contenu parfaitement propre.</p>',
        ]));

        $this->assertSame([], $issues);
    }

    /**
     * Un crochet présent dans un attribut HTML n'est pas visible par le
     * visiteur : il ne doit pas produire de fausse alerte.
     */
    public function test_un_crochet_dans_un_attribut_html_est_ignore(): void
    {
        $issues = (new ShortcodeRule)->evaluate($this->context([
            'content' => '<div data-items="[1,2,3]"><p>Texte propre.</p></div>',
        ]));

        $this->assertSame([], $issues);
    }

    /* --- Titre ------------------------------------------------------------- */

    public function test_un_h1_de_plus_de_20_mots_est_signale(): void
    {
        $issues = (new LongTitleRule)->evaluate($this->context([
            'title' => trim(str_repeat('mot ', 21)),
        ]));

        $this->assertSame(['long_title'], $this->types($issues));
        $this->assertSame(21, $issues[0]->metadata['words']);
        $this->assertSame(20, $issues[0]->metadata['max']);
        $this->assertSame('H1 trop long (max : 20 mots)', $issues[0]->message);
    }

    public function test_un_h1_de_exactement_20_mots_est_accepte(): void
    {
        $issues = (new LongTitleRule)->evaluate($this->context([
            'title' => trim(str_repeat('mot ', 20)),
        ]));

        $this->assertSame([], $issues);
    }

    /**
     * Le nombre de caractères n'entre plus en jeu : un titre très long mais
     * composé de peu de mots reste accepté.
     */
    public function test_un_h1_long_en_caracteres_mais_court_en_mots_est_accepte(): void
    {
        $issues = (new LongTitleRule)->evaluate($this->context([
            'title' => str_repeat('a', 150).' '.str_repeat('b', 150),
        ]));

        $this->assertSame([], $issues);
    }

    /**
     * Les séparateurs isolés ne sont pas des mots : « Bagues : le guide »
     * compte trois mots, pas quatre.
     */
    public function test_les_separateurs_isoles_ne_comptent_pas_comme_des_mots(): void
    {
        $this->assertSame(3, LongTitleRule::countWords('Bagues : le guide'));
        $this->assertSame(4, LongTitleRule::countWords("  Or   blanc — porte-clés d'été "));
        $this->assertSame(0, LongTitleRule::countWords('   '));
    }

    public function test_le_seuil_de_titre_est_configurable(): void
    {
        config(['articleguard.thresholds.title_max_words' => 5]);

        $issues = (new LongTitleRule)->evaluate($this->context([
            'title' => 'un titre de six mots pile',
        ]));

        $this->assertSame(['long_title'], $this->types($issues));
    }

    /* --- Titres H1 / H2 ---------------------------------------------------- */

    public function test_deux_h1_sont_signales(): void
    {
        $issues = (new H1Rule)->evaluate($this->context([
            'content' => '<h1>Premier</h1><p>…</p><h1>Second</h1>',
        ]));

        $this->assertSame(['multiple_h1'], $this->types($issues));
        $this->assertSame('2 balises H1 détectées', $issues[0]->message);
        $this->assertSame(['Premier', 'Second'], $issues[0]->metadata['headings']);
    }

    public function test_un_seul_h1_est_conforme(): void
    {
        $issues = (new H1Rule)->evaluate($this->context([
            'content' => '<h1>Unique</h1><p>…</p>',
        ]));

        $this->assertSame([], $issues);
    }

    public function test_l_absence_de_h1_n_est_signalee_que_si_la_regle_est_activee(): void
    {
        $content = ['content' => '<p>Aucun titre.</p>'];

        $this->assertSame([], (new H1Rule)->evaluate($this->context($content, ['missing_h1' => false])));

        $issues = (new H1Rule)->evaluate($this->context($content, ['missing_h1' => true]));
        $this->assertSame(['missing_h1'], $this->types($issues));
    }

    public function test_le_titre_wordpress_n_est_pas_compte_comme_h1(): void
    {
        // Le titre de l'article contient le mot H1 mais n'est pas dans le contenu.
        $issues = (new H1Rule)->evaluate($this->context([
            'title' => 'Un titre qui serait rendu en H1 par le thème',
            'content' => '<h1>Le seul H1 du contenu</h1>',
        ]));

        $this->assertSame([], $issues);
    }

    public function test_l_absence_de_h2_est_signalee(): void
    {
        $issues = (new H2Rule)->evaluate($this->context([
            'content' => '<h1>Titre</h1><p>Sans sous-titre.</p>',
        ]));

        $this->assertSame(['missing_h2'], $this->types($issues));
    }
}
