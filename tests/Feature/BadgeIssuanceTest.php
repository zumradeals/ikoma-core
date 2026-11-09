<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeIssuance;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\CardLifecycle;
use App\Services\InstitutionalCategories;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Badges et cartes — contrat d'émission canonique (BadgeIssuance) : identifiabilité des opérations, idempotence, refus d'un second cycle ouvert, remplacement explicite,
 * réaffectation distincte d'une émission, génération groupée par le même contrat, contraintes d'unicité côté base. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function biUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif émission', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{id: string, category: string} */
function biEvent(?string $status = null): array
{
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', 'status' => $status, ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);

    return ['id' => $id, 'category' => $category];
}

/** Participation d'une nouvelle personne canonique. */
function biParticipation(array $event, string $name = 'Zzemission'): array
{
    $person = app(Persons::class)->create(test()->admin, ['full_name' => $name.' '.Str::lower(Str::random(6))]);
    $participation = app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $event['id'], 'category_id' => $event['category']]);

    return ['person' => $person, 'id' => $participation];
}

function biOp(): string
{
    return (string) Str::uuid();
}

function biAudit(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

/** @return array{holder: string, person: string} titulaire actif, catégorie institutionnelle, photo enregistrée. */
function biHolder(bool $photo = true): array
{
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzcarte '.Str::lower(Str::random(6))]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], test()->admin, false, $person);
    if ($photo) {
        DB::table('permanent_holders')->where('id', $holder)->update(['photo_file' => 'portraits/fictif-'.Str::random(6).'.jpg']);
    }

    return ['holder' => $holder, 'person' => $person];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = biUser('administrateur');
    $this->agent = biUser('agent_accueil');
    $this->supervisor = biUser('superviseur');
    $this->event = biEvent();
    $this->issuance = app(BadgeIssuance::class);
    app(InstitutionalCategories::class)->initialize($this->admin);
});

// --- 1 à 3 : émission unitaire, support nominatif sans droit ni réservation ---------------------------------------------------------------------------

it('prépare un badge temporaire pour une personne existante : numéro et jeton uniques, support nominatif disponible, sans habilitation ni réservation', function () {
    $p = biParticipation($this->event);
    $entitlements = DB::table('participation_entitlements')->count();
    $persons = DB::table('persons')->count();
    $audit = biAudit('badge.emis');
    $revision = (int) DB::table('events')->where('id', $this->event['id'])->value('list_revision');

    $r = $this->issuance->issueTemporary($p['id'], $op = biOp(), $this->admin);

    $badge = Badge::findOrFail($r['badge_id']);
    expect($r['created'])->toBeTrue()->and($r['purpose'])->toBe('emission')->and($r['person_id'])->toBe($p['person'])->and($r['visitor_id'])->toBe($p['id'])
        ->and($badge->status->value)->toBe('Disponible')->and($badge->printed_for_visitor_id)->toBe($p['id'])->and($badge->reserved_device_id)->toBeNull()->and($badge->reserved_group_id)->toBeNull()
        ->and($badge->code)->toMatch('/^B-\d+$/')->and($badge->qr_token)->toMatch('/^[0-9a-f]{64}$/')->and($badge->qr_token)->not->toContain($badge->code)
        ->and(DB::table('participation_entitlements')->count())->toBe($entitlements)->and(DB::table('persons')->count())->toBe($persons)
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->count())->toBe(0)
        ->and((int) DB::table('events')->where('id', $this->event['id'])->value('list_revision'))->toBe($revision + 1);
    // l'opération, la personne canonique et le contexte sont enregistrés ; l'audit n'expose jamais le secret du jeton
    expect(DB::table('badge_issuances')->where('operation_id', $op)->where('person_id', $p['person'])->where('visitor_id', $p['id'])->where('support_type', 'badge_temporaire')->count())->toBe(1)
        ->and(biAudit('badge.emis'))->toBe($audit + 1)
        ->and(DB::table('audit_logs')->where('action', 'badge.emis')->orderByDesc('id')->value('details'))->not->toContain($badge->qr_token);
});

it('rejoue la même opération sans créer de doublon (idempotence) et refuse une opération distincte tant qu’un support est en cours', function () {
    $p = biParticipation($this->event);
    $first = $this->issuance->issueTemporary($p['id'], $op = biOp(), $this->admin);
    $again = $this->issuance->issueTemporary($p['id'], $op, $this->admin);
    expect($again['created'])->toBeFalse()->and($again['badge_id'])->toBe($first['badge_id'])->and($again['code'])->toBe($first['code'])
        ->and(DB::table('badges')->where('printed_for_visitor_id', $p['id'])->count())->toBe(1)->and(DB::table('badge_issuances')->where('visitor_id', $p['id'])->count())->toBe(1);
    // une demande DISTINCTE (autre identifiant d'opération) ne crée pas de doublon : elle indique les voies légitimes
    expect(fn () => $this->issuance->issueTemporary($p['id'], biOp(), $this->admin))->toThrow(BadgeRefused::class, 'conservez-le, réimprimez-le');
    expect(DB::table('badges')->where('printed_for_visitor_id', $p['id'])->count())->toBe(1);
    // un identifiant d'opération ne peut pas servir pour une autre personne
    $other = biParticipation($this->event);
    expect(fn () => $this->issuance->issueTemporary($other['id'], $op, $this->admin))->toThrow(BadgeRefused::class, 'déjà servi');
    expect(DB::table('badges')->where('printed_for_visitor_id', $other['id'])->count())->toBe(0);
});

it('garantit l’unicité par des contraintes de la base : opération, support nominatif en cours, support d’origine', function () {
    $p = biParticipation($this->event);
    $r = $this->issuance->issueTemporary($p['id'], $op = biOp(), $this->admin);
    $violation = function (callable $fn): string {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'aucune';
    };
    // même opération insérée deux fois
    expect($violation(fn () => DB::table('badge_issuances')->insert(['id' => (string) Str::uuid(), 'operation_id' => $op, 'purpose' => 'emission', 'support_type' => 'badge_temporaire', 'person_id' => $p['person'],
        'visitor_id' => $p['id'], 'event_id' => $this->event['id'], 'badge_id' => (string) Str::uuid(), 'created_at' => now()])))->toBe('23505');
    // deuxième support nominatif en cours pour la même participation, inséré directement
    $second = fn () => DB::table('badges')->insert(['id' => (string) Str::uuid7(), 'event_id' => $this->event['id'], 'category_id' => $this->event['category'], 'badge_batch_id' => Badge::findOrFail($r['badge_id'])->badge_batch_id,
        'code' => 'B-9'.random_int(100000, 999999), 'qr_token' => bin2hex(random_bytes(32)), 'status' => 'Disponible', 'printed_for_visitor_id' => $p['id'], 'created_at' => now(), 'updated_at' => now()]);
    expect($violation($second))->toBe('23505');
    // une ligne du registre ne se modifie pas
    expect(fn () => DB::table('badge_issuances')->where('operation_id', $op)->update(['purpose' => 'remplacement']))->toThrow(QueryException::class);
});

// --- 4 : non éligibles ---------------------------------------------------------------------------------------------------------------------------------

it('refuse les participations non éligibles et signale la raison', function () {
    $closed = biParticipation($this->event);
    app(Participations::class)->close($this->admin, $closed['id'], 'Fin du stage');
    $withdrawn = biParticipation($this->event);
    DB::table('visitors')->where('id', $withdrawn['id'])->update(['withdrawn_at' => now()]);
    $unlinked = (string) Str::uuid();
    DB::table('visitors')->insert(['id' => $unlinked, 'event_id' => $this->event['id'], 'person_id' => null, 'full_name' => 'Invité sans identité', 'category_id' => $this->event['category'], 'valid_from' => '2027-01-01 08:00:00+00',
        'valid_until' => '2027-03-01 18:00:00+00', 'source' => 'listé', 'status' => 'attendu', 'created_at' => now(), 'updated_at' => now()]);
    $ended = biEvent();
    $inEnded = biParticipation($ended);
    DB::table('events')->where('id', $ended['id'])->update(['status' => 'clos']);
    $cases = [[$closed['id'], 'participation_cloturee'], [$withdrawn['id'], 'invite_retire'], [$unlinked, 'identite_non_rattachee'], [$inEnded['id'], 'evenement_indisponible'], [(string) Str::uuid(), 'participation_inconnue']];
    foreach ($cases as [$id, $reason]) {
        try {
            $this->issuance->issueTemporary($id, biOp(), $this->admin);
            $this->fail("Émission acceptée à tort ($reason).");
        } catch (BadgeRefused $e) {
            expect($e->reason)->toBe($reason);
        }
    }
    expect(DB::table('badges')->whereIn('printed_for_visitor_id', [$closed['id'], $withdrawn['id'], $unlinked, $inEnded['id']])->count())->toBe(0);
});

it('refuse les opérations non autorisées côté serveur', function () {
    $p = biParticipation($this->event);
    foreach ([$this->agent, $this->supervisor] as $user) {
        expect(fn () => $this->issuance->issueTemporary($p['id'], biOp(), $user))->toThrow(HttpException::class);
        expect(fn () => $this->issuance->issueMany('badge_temporaire', [$p['id']], biOp(), $user))->toThrow(HttpException::class);
    }
    expect(fn () => $this->issuance->issueTemporary($p['id'], 'pas-un-uuid', $this->admin))->toThrow(BadgeRefused::class, 'invalide');
    expect(DB::table('badges')->where('printed_for_visitor_id', $p['id'])->count())->toBe(0);
});

// --- 5 : remplacement explicite --------------------------------------------------------------------------------------------------------------------------

it('permet une nouvelle émission légitime après annulation, en tant que remplacement explicite, sans réutiliser le support annulé', function () {
    $p = biParticipation($this->event);
    $first = $this->issuance->issueTemporary($p['id'], biOp(), $this->admin);
    // un support en cours ne se « remplace » pas par contournement
    expect(fn () => $this->issuance->issueTemporary($p['id'], biOp(), $this->admin, $first['badge_id']))->toThrow(BadgeRefused::class);
    app(BadgeService::class)->annuler($first['badge_id'], $this->supervisor, 'Support perdu');
    $new = $this->issuance->issueTemporary($p['id'], biOp(), $this->admin, $first['badge_id']);
    expect($new['purpose'])->toBe('remplacement')->and($new['badge_id'])->not->toBe($first['badge_id'])->and($new['code'])->not->toBe($first['code'])
        ->and(DB::table('badge_issuances')->where('badge_id', $new['badge_id'])->value('replaces_badge_id'))->toBe($first['badge_id'])
        ->and(Badge::findOrFail($first['badge_id'])->status->value)->toBe('Annulé');
    // le même support ne se remplace qu'une fois ; un support qui n'est pas annulé ne se « remplace » pas
    app(BadgeService::class)->annuler($new['badge_id'], $this->supervisor, 'Support perdu');
    expect(fn () => $this->issuance->issueTemporary($p['id'], biOp(), $this->admin, $first['badge_id']))->toThrow(BadgeRefused::class, 'déjà été remplacé');
    // historique conservé : deux émissions d'origine pour la participation
    expect(DB::table('badge_issuances')->where('visitor_id', $p['id'])->whereIn('purpose', ['emission', 'remplacement'])->count())->toBe(2);
});

// --- 6 : récapitulatif et génération groupée -------------------------------------------------------------------------------------------------------------

it('établit le récapitulatif avant génération : à générer, déjà pourvus, non éligibles, doublons de sélection ignorés', function () {
    $a = biParticipation($this->event);
    $b = biParticipation($this->event);
    $c = biParticipation($this->event);
    app(Participations::class)->close($this->admin, $c['id'], 'Fin du stage');
    $this->issuance->issueTemporary($b['id'], biOp(), $this->admin);
    $plan = $this->issuance->planTemporary([$a['id'], $b['id'], $c['id'], $a['id'], 'x']);
    expect($plan['a_generer'])->toBe([$a['id']])->and($plan['existants'])->toHaveCount(1)->and($plan['existants'][0]['id'])->toBe($b['id'])
        ->and(array_column($plan['non_eligibles'], 'code'))->toBe(['participation_cloturee', 'participation_inconnue'])->and($plan['doublons_ignores'])->toBe(1);
});

it('génère en groupe par le même contrat : numéros et jetons uniques, aucun doublon si la sélection ou la demande se répète, réussites et échecs distincts', function () {
    $people = [biParticipation($this->event), biParticipation($this->event), biParticipation($this->event)];
    $closed = biParticipation($this->event);
    app(Participations::class)->close($this->admin, $closed['id'], 'Fin du stage');
    $ids = [...array_column($people, 'id'), $closed['id'], $people[0]['id']]; // sélection répétée
    $entitlements = DB::table('participation_entitlements')->count();
    $batchOp = biOp();

    $r = $this->issuance->issueMany('badge_temporaire', $ids, $batchOp, $this->admin);
    expect($r['complete'])->toBeFalse()->and($r['created'])->toHaveCount(3)->and($r['existing'])->toBe([])->and($r['failed'])->toHaveCount(1)->and($r['failed'][0]['code'])->toBe('participation_cloturee');
    $codes = array_column($r['created'], 'code');
    $badges = Badge::whereIn('id', array_column($r['created'], 'badge_id'))->get();
    expect(array_unique($codes))->toHaveCount(3)->and($badges->pluck('qr_token')->unique())->toHaveCount(3)
        ->and(DB::table('participation_entitlements')->count())->toBe($entitlements)
        ->and(DB::table('badges')->whereIn('printed_for_visitor_id', array_column($people, 'id'))->count())->toBe(3);

    // même demande rejouée (reprise après interruption) : aucun nouveau support, les existants sont rendus
    $again = $this->issuance->issueMany('badge_temporaire', $ids, $batchOp, $this->admin);
    expect($again['created'])->toBe([])->and($again['existing'])->toHaveCount(3)->and(array_column($again['existing'], 'badge_id'))->toEqualCanonicalizing(array_column($r['created'], 'badge_id'))
        ->and(DB::table('badges')->whereIn('printed_for_visitor_id', array_column($people, 'id'))->count())->toBe(3);
    // nouvelle opération de lot sur les mêmes personnes : les supports en cours sont conservés, jamais dupliqués
    $third = $this->issuance->issueMany('badge_temporaire', array_column($people, 'id'), biOp(), $this->admin);
    expect($third['created'])->toBe([])->and($third['existing'])->toHaveCount(3)->and($third['complete'])->toBeTrue()
        ->and(DB::table('badges')->whereIn('printed_for_visitor_id', array_column($people, 'id'))->count())->toBe(3);
});

it('reprend une génération groupée interrompue sans duplication', function () {
    $people = [biParticipation($this->event), biParticipation($this->event), biParticipation($this->event)];
    $batchOp = biOp();
    // première personne déjà traitée avant l'interruption (opération dérivée stable du lot)
    $done = $this->issuance->issueTemporary($people[0]['id'], Uuid::uuid5($batchOp, 'badge_temporaire:'.$people[0]['id'])->toString(), $this->admin);
    $r = $this->issuance->issueMany('badge_temporaire', array_column($people, 'id'), $batchOp, $this->admin);
    expect($r['complete'])->toBeTrue()->and($r['created'])->toHaveCount(2)->and($r['existing'])->toHaveCount(1)->and($r['existing'][0]['badge_id'])->toBe($done['badge_id'])
        ->and(DB::table('badges')->whereIn('printed_for_visitor_id', array_column($people, 'id'))->count())->toBe(3);
});

// --- 7 : cartes permanentes ------------------------------------------------------------------------------------------------------------------------------

it('prépare en groupe les cartes de titulaires permanents éligibles, sans activation ni habilitation, et signale les inéligibles', function () {
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $a = biHolder();
    $b = biHolder();
    $noPhoto = biHolder(false);
    $entitlements = DB::table('holder_entitlements')->count();
    $plan = $this->issuance->planCards([$a['holder'], $b['holder'], $noPhoto['holder'], $a['holder']]);
    expect($plan['a_generer'])->toBe([$a['holder'], $b['holder']])->and($plan['non_eligibles'][0]['code'])->toBe('photo_manquante')->and($plan['doublons_ignores'])->toBe(1);

    $r = $this->issuance->issueMany('carte_permanente', [$a['holder'], $b['holder'], $noPhoto['holder'], $a['holder']], $batchOp = biOp(), $this->admin);
    expect($r['complete'])->toBeFalse()->and($r['created'])->toHaveCount(2)->and($r['failed'])->toHaveCount(1)->and($r['failed'][0]['code'])->toBe('photo_manquante');
    foreach ($r['created'] as $card) {
        expect($card['support_type'])->toBe('carte_permanente')->and($card['holder_id'])->not->toBeNull()
            ->and(DB::table('holder_cards')->where('holder_id', $card['holder_id'])->where('status', 'active')->whereNull('activated_at')->count())->toBe(1);
    }
    expect(DB::table('holder_entitlements')->count())->toBe($entitlements);
    // rejeu : aucune seconde carte active
    $again = $this->issuance->issueMany('carte_permanente', [$a['holder'], $b['holder']], $batchOp, $this->admin);
    expect($again['created'])->toBe([])->and($again['existing'])->toHaveCount(2)->and(DB::table('holder_cards')->whereIn('holder_id', [$a['holder'], $b['holder']])->where('status', 'active')->count())->toBe(2);
    // une carte en cours refuse une nouvelle émission distincte : la voie légitime est le remplacement
    expect(fn () => $this->issuance->issueCard($a['holder'], biOp(), $this->admin))->toThrow(BadgeRefused::class, 'remplacement');
});

it('enregistre aussi dans le registre canonique le remplacement d’une carte permanente, sans réutiliser le support remplacé', function () {
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $a = biHolder();
    $first = $this->issuance->issueCard($a['holder'], biOp(), $this->admin);
    $newCard = app(CardLifecycle::class)->replace($a['holder'], 'perdue', null, $this->admin, false, $op = biOp());
    $ledger = DB::table('badge_issuances')->where('operation_id', $op)->first();
    expect($ledger->purpose)->toBe('remplacement')->and($ledger->replaces_badge_id)->toBe($first['badge_id'])->and($ledger->badge_id)->not->toBe($first['badge_id'])
        ->and(DB::table('holder_cards')->where('id', $newCard)->value('badge_id'))->toBe($ledger->badge_id)
        ->and(DB::table('badges')->where('id', $first['badge_id'])->value('status'))->toBe('Annulé');
});

// --- 8 : réaffectation d'un badge temporaire restitué ------------------------------------------------------------------------------------------------------

it('réaffecte un badge restitué en conservant son identité technique et crée une nouvelle affectation, distincte d’une émission', function () {
    DB::table('settings')->updateOrInsert(['key' => 'badge_reutilisable'], ['value' => 'oui']);
    $one = biParticipation($this->event);
    $two = biParticipation($this->event);
    $three = biParticipation($this->event);
    $batch = app(BadgeLots::class)->generate($this->event['id'], $this->event['category'], 2, $this->admin);
    $badge = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->first();
    $service = app(BadgeService::class);
    // jamais restitué : la réaffectation est refusée (c'est une simple association ou une émission)
    expect(fn () => $this->issuance->reassign($badge->id, $one['id'], biOp(), $this->agent))->toThrow(BadgeRefused::class, 'jamais été restitué');
    $first = $service->associer($badge->id, $one['id'], $this->agent);
    $service->restituer($badge->id, $this->agent);
    expect(Badge::findOrFail($badge->id)->status->value)->toBe('Disponible');
    $issuances = DB::table('badge_issuances')->count();

    $r = $this->issuance->reassign($badge->id, $two['id'], $op = biOp(), $this->agent);
    expect($r['purpose'])->toBe('reaffectation')->and($r['badge_id'])->toBe($badge->id)->and($r['code'])->toBe($badge->code)->and($r['assignment_id'])->not->toBe($first->id)
        ->and(Badge::findOrFail($badge->id)->qr_token)->toBe($badge->qr_token)->and(Badge::findOrFail($badge->id)->status->value)->toBe('Associé')
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->count())->toBe(2)
        ->and(DB::table('badge_assignments')->where('id', $first->id)->value('returned_at'))->not->toBeNull()
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNull('returned_at')->whereNull('cancelled_at')->value('visitor_id'))->toBe($two['id'])
        ->and(DB::table('badge_issuances')->count())->toBe($issuances + 1)
        ->and(DB::table('badges')->where('id', $badge->id)->count())->toBe(1);
    // rejeu de l'opération : même résultat, sans seconde affectation
    $again = $this->issuance->reassign($badge->id, $two['id'], $op, $this->agent);
    expect($again['created'])->toBeFalse()->and($again['assignment_id'])->toBe($r['assignment_id'])->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->count())->toBe(2);
    // pas de réaffectation simultanée du même support, ni de reprise des anciennes habilitations (aucune n'a été créée)
    expect(fn () => $this->issuance->reassign($badge->id, $three['id'], biOp(), $this->agent))->toThrow(BadgeRefused::class);
    expect(DB::table('participation_entitlements')->where('visitor_id', $two['id'])->count())->toBe(0);
});

it('refuse la réaffectation d’un support nominatif d’une autre personne et aux comptes sans droit d’association', function () {
    DB::table('settings')->updateOrInsert(['key' => 'badge_reutilisable'], ['value' => 'oui']);
    $one = biParticipation($this->event);
    $two = biParticipation($this->event);
    $nominative = $this->issuance->issueTemporary($one['id'], biOp(), $this->admin);
    $assignment = app(BadgeService::class)->associer($nominative['badge_id'], $one['id'], $this->agent);
    app(BadgeService::class)->restituer($nominative['badge_id'], $this->agent);
    expect($assignment->id)->not->toBeEmpty();
    expect(fn () => $this->issuance->reassign($nominative['badge_id'], $two['id'], biOp(), $this->agent))->toThrow(BadgeRefused::class, 'appartient à une autre personne');
    expect(fn () => $this->issuance->reassign($nominative['badge_id'], $one['id'], biOp(), $this->admin))->toThrow(HttpException::class);
});
