<?php

declare(strict_types=1);

// Chaînes de licence (docs/specs/08-operations.md §4). Règle contraignante
// du §4.3 : chaque cas d'échec a sa PROPRE phrase, EN et FR, avec un test
// (LicenceVerificationTest) qui fait échouer la build si deux phrases se
// confondent. Ne « simplifiez » jamais deux de ces phrases en une seule.
// La clé de licence n'est jamais interpolée dans aucune d'entre elles.
return [

    'state' => [
        'valid' => 'Sous licence',
        'trial' => 'Période d\'essai',
        'expiring' => 'Expire bientôt',
        'grace' => 'Expirée — période de tolérance',
        'enforced' => 'Expirée',
        'revoked' => 'Révoquée',
    ],

    'blocked' => [
        'enforced' => 'La licence a expiré : :operation est indisponible. Le travail quotidien — encaissements, reçus, présences, notes, paie et toutes les exportations — continue sans restriction. Renouvelez la licence pour continuer.',
        'revoked' => 'La licence a été révoquée : :operation est indisponible. Le travail quotidien — encaissements, reçus, présences, notes, paie et toutes les exportations — continue sans restriction. Contactez votre fournisseur.',
    ],

    'operation' => [
        'academics' => [
            'create_year' => 'la création d\'une nouvelle année scolaire',
        ],
        'assessment' => [
            'publish_period' => 'la publication des bulletins',
        ],
        'operations' => [
            'rollover' => 'l\'assistant de passage d\'année',
        ],
        'documents' => [
            'bulk_generate' => 'la génération de documents en masse',
        ],
    ],

    'failure' => [
        'payload_unreadable' => 'La licence enregistrée n\'a pas pu être lue. Importez à nouveau votre fichier de licence, ou réactivez.',
        'file_signature_invalid' => 'Le fichier de licence enregistré ne passe plus la vérification de signature ; il est donc ignoré.',
        'activation_signature_invalid' => 'L\'activation enregistrée ne passe plus la vérification de signature ; elle est donc ignorée.',
        'wrong_product' => 'La licence enregistrée a été émise pour un autre produit ; elle est donc ignorée.',
        'fingerprint_mismatch' => 'La licence enregistrée est liée à un autre ordinateur ; elle est donc ignorée sur celui-ci.',
        'expiry_missing' => 'La licence enregistrée ne comporte aucune date d\'expiration lisible ; elle est donc ignorée.',
    ],

    'import' => [
        'not_json' => 'Ceci n\'est pas un fichier de licence — il n\'a pas pu être lu du tout. Demandez à votre fournisseur de renvoyer le fichier .opeslic.',
        'malformed' => 'Le fichier de licence est incomplet : il lui manque son contenu ou sa signature.',
        'signature_invalid' => 'Le fichier de licence a échoué à la vérification de signature. Il a peut-être été altéré en transit — demandez à votre fournisseur de le renvoyer.',
        'wrong_product' => 'Ce fichier de licence a été émis pour un autre produit et ne peut pas être importé ici.',
        'expiry_missing' => 'Le fichier de licence ne comporte aucune date d\'expiration lisible et ne peut pas être importé.',
        'done' => 'Fichier de licence importé. Cet établissement est sous licence jusqu\'au :date.',
    ],

    'activate' => [
        'no_server' => 'Aucun serveur d\'activation n\'est configuré sur cette installation : l\'activation en ligne est indisponible. Importez plutôt un fichier de licence.',
        'no_fingerprint' => 'L\'identité de cet ordinateur n\'a pas pu être lue : il ne peut pas être lié à une licence. Aucune demande d\'activation n\'a été envoyée — importez plutôt un fichier de licence.',
        'unreachable' => 'Le serveur d\'activation est injoignable. Vérifiez la connexion internet et réessayez, ou importez plutôt un fichier de licence.',
        'invalid_key' => 'Le serveur d\'activation n\'a pas reconnu cette clé de licence. Vérifiez qu\'elle ne comporte pas de faute de frappe.',
        'no_seats' => 'Cette clé de licence n\'a plus de poste disponible. Désactivez un ancien ordinateur dans votre compte fournisseur, puis réessayez.',
        'rejected' => 'Le serveur d\'activation a refusé la demande. Contactez votre fournisseur.',
        'malformed_response' => 'La réponse du serveur d\'activation était incomplète et a été rejetée.',
        'signature_invalid' => 'La réponse du serveur d\'activation a échoué à la vérification de signature et a été rejetée.',
        'wrong_product' => 'Le serveur d\'activation a répondu avec une licence pour un autre produit ; elle a été rejetée.',
        'fingerprint_mismatch' => 'Le serveur d\'activation a répondu avec une licence liée à un autre ordinateur ; elle a été rejetée.',
        'expiry_missing' => 'La réponse du serveur d\'activation ne comporte aucune date d\'expiration lisible et a été rejetée.',
        'done' => 'Activation réussie. Cet ordinateur est sous licence jusqu\'au :date.',
    ],

    'deactivate' => [
        'none' => 'Il n\'y a aucune licence à retirer de cet ordinateur.',
        'done' => 'La licence a été retirée de cet ordinateur.',
        'seat_released' => 'La licence a été retirée de cet ordinateur et son poste a été libéré dans votre compte fournisseur.',
        'seat_not_released' => 'La licence a été retirée de cet ordinateur, mais celui-ci compte toujours parmi les postes de votre licence ; désactivez-le dans votre compte fournisseur.',
    ],

    'panel' => [
        'title' => 'Licence',
        'subtitle' => 'Comment cette installation est licenciée, et comment la renouveler.',
        'breadcrumb_dashboard' => 'Tableau de bord',
        'breadcrumb_settings' => 'Paramètres',
        'breadcrumb_licence' => 'État de la licence',
        'status_card' => 'État de la licence',
        'expires_on' => 'Expire le',
        'days_left' => ':days jour(s) restant(s)',
        'trial_intro' => 'Cette installation fonctionne avec l\'essai intégré : 30 jours ou 25 élèves, la première de ces limites atteinte.',
        'trial_ends' => 'Fin de la fenêtre d\'essai',
        'trial_clock_unset' => 'Le compteur d\'essai démarre lorsque l\'installateur termine la configuration initiale.',
        'students_on_books' => 'Élèves inscrits au registre',
        'grace_note' => 'Tout continue de fonctionner pendant la période de tolérance. Renouvelez maintenant pour éviter la mise en pause des opérations de fin d\'année.',
        'enforced_note' => 'La création d\'une nouvelle année scolaire, la publication des bulletins, l\'assistant de passage d\'année et la génération de documents en masse sont en pause. Les encaissements, reçus, présences, notes, la paie et toutes les exportations ne sont jamais bloqués.',
        'details_card' => 'Détails de la licence',
        'holder' => 'Licence accordée à',
        'edition' => 'Édition',
        'student_cap' => 'Plafond d\'élèves',
        'source' => 'Provenance',
        'source_file' => 'Fichier de licence (hors ligne)',
        'source_activation' => 'Activation en ligne (liée à la machine)',
        'unlimited' => 'Illimité',
        'import_card' => 'Importer un fichier de licence',
        'import_help' => 'Collez le contenu du fichier .opeslic envoyé par votre fournisseur. Rien n\'est transmis sur le réseau — le fichier est vérifié sur cet ordinateur.',
        'import_placeholder' => 'Collez ici le contenu du fichier .opeslic…',
        'import_button' => 'Importer le fichier de licence',
        'activate_card' => 'Activer en ligne',
        'activate_help' => 'Saisissez votre clé de licence. C\'est la seule étape qui nécessite internet — une seule fois. La licence signée est ensuite vérifiée hors ligne pour toujours.',
        'activate_placeholder' => 'Clé de licence',
        'activate_button' => 'Activer cet ordinateur',
        'deactivate_card' => 'Retirer la licence de cet ordinateur',
        'deactivate_help' => 'Vous changez d\'ordinateur ? Retirer la licence ici libère aussi votre poste dans le compte fournisseur lorsque le serveur est joignable.',
        'deactivate_button' => 'Retirer la licence',
        'never_blocked_title' => 'Jamais bloqué, quel que soit l\'état de la licence',
        'never_blocked_body' => 'Les encaissements et l\'impression des reçus, les présences, la saisie des notes, la paie, le grand livre et toutes les exportations de données. C\'est un engagement produit, inscrit dans votre contrat.',
    ],
];
