<?php

declare(strict_types=1);

// docs/specs/10-documents.md 17.2 - écran de vérification des documents.
return [
    'title' => 'Vérifier un document',
    'subtitle' => 'Collez ou scannez le jeton QR imprimé sur le document. La signature est vérifiée avec les clés propres de l\'établissement — sans Internet.',
    'token_label' => 'Jeton de vérification',
    'token_placeholder' => 'OPES1.…',
    'token_required' => 'Collez ou scannez d\'abord un jeton de vérification.',
    'check' => 'Vérifier',
    'status_valid' => 'VALIDE',
    'status_revoked' => 'RÉVOQUÉ',
    'status_superseded' => 'REMPLACÉ',
    'status_not_found' => 'INTROUVABLE',
    'not_found_help' => 'Ce jeton ne correspond à aucun document émis par cet établissement. Vérifiez que le jeton complet a été scanné ou collé.',
    'superseded_help' => 'Ce document a été réémis. Le document en vigueur porte le numéro :serial.',
    'revoked_help' => 'Ce document a été révoqué par l\'établissement et n\'est plus valide.',
    'detail_serial' => 'Numéro',
    'detail_template' => 'Document',
    'detail_issued_on' => 'Émis le',
    'detail_issuer' => 'Émis par',
    'detail_superseded_by' => 'Remplacé par',
    'empty_hint' => 'En attente d\'un jeton.',
];
