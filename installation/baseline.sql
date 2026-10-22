-- Community baseline 1 : installation neuve uniquement, sans données personnelles.
-- Comptes conservés dans le stockage privé hors racine web.

CREATE TABLE contacts_carnet_adresse (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(255) NOT NULL,
    source_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nom TEXT NULL,
    contact TEXT NULL,
    mail TEXT NULL,
    tel TEXT NULL,
    adresse TEXT NULL,
    statut TEXT NULL,
    commentaires LONGTEXT NULL,
    source_json LONGTEXT NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY contacts_source (source_identity, source_table, source_key_hash),
    dernier_contact DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_extracted_at VARCHAR(40) NOT NULL,
    imported_by VARCHAR(254) NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    added_count INT UNSIGNED NOT NULL,
    unchanged_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY contacts_import_file (file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id BIGINT UNSIGNED NOT NULL,
    edited_by VARCHAR(254) NOT NULL,
    edited_at DATETIME(6) NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    PRIMARY KEY (id), KEY contacts_history (record_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concerts_evenements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(255) NOT NULL,
    source_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evenement TEXT NULL,
    date_concert TEXT NULL,
    adresse TEXT NULL,
    statut TEXT NULL,
    client TEXT NULL,
    contact TEXT NULL,
    heure_arrivee TEXT NULL,
    heure_balances TEXT NULL,
    heure_prestation TEXT NULL,
    duree TEXT NULL,
    commentaires LONGTEXT NULL,
    contact_id BIGINT UNSIGNED NULL,
    source_json LONGTEXT NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY concerts_source (source_identity, source_table, source_key_hash),
    KEY concerts_contact (contact_id),
    CONSTRAINT concerts_contact_fk
    FOREIGN KEY (contact_id) REFERENCES contacts_carnet_adresse(id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
    montant TEXT NULL,
    reglement TEXT NULL,
    extra_fields_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicband_publications (
    publication_key CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(255) NOT NULL,
    concert_location TEXT NULL,
    target_seconds INT UNSIGNED NULL,
    source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (publication_key),
    UNIQUE KEY Musicband_publication_source (source_file),
    concert_id BIGINT UNSIGNED NULL,
    concert_link_by VARCHAR(254) NULL,
    concert_link_at DATETIME(6) NULL,
    KEY Musicband_publication_concert (concert_id),
    CONSTRAINT Musicband_publication_concert_fk FOREIGN KEY (concert_id)
        REFERENCES concerts_evenements(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicband_variants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    publication_key CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    variant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_variant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    name VARCHAR(255) NOT NULL,
    saved_by VARCHAR(254) NULL,
    created_at DATETIME(6) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY Musicband_publication_variant (publication_key, variant_key),
    CONSTRAINT Musicband_variant_publication FOREIGN KEY (publication_key)
        REFERENCES Musicband_publications (publication_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicband_variant_songs (
    variant_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    editor_id VARCHAR(64) NOT NULL,
    title TEXT NOT NULL,
    artist TEXT NOT NULL,
    section VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    PRIMARY KEY (variant_id, position),
    CONSTRAINT Musicband_song_variant FOREIGN KEY (variant_id)
        REFERENCES Musicband_variants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concerts_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_extracted_at VARCHAR(40) NOT NULL,
    imported_by VARCHAR(254) NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    added_count INT UNSIGNED NOT NULL,
    unchanged_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY concerts_import_file (file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concerts_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id BIGINT UNSIGNED NOT NULL,
    edited_by VARCHAR(254) NOT NULL,
    edited_at DATETIME(6) NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    PRIMARY KEY (id), KEY concerts_history (record_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_field_visibility (
    module VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    hidden_json LONGTEXT NOT NULL,
    revision CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by VARCHAR(254) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devis_factures_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_identity CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(255) NOT NULL,
    source_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    numero TEXT NULL,
    type_document TEXT NULL,
    statut TEXT NULL,
    date_document TEXT NULL,
    client TEXT NULL,
    adresse TEXT NULL,
    date_prestation TEXT NULL,
    duree TEXT NULL,
    montant TEXT NULL,
    reglement TEXT NULL,
    lien_document TEXT NULL,
    commentaires LONGTEXT NULL,
    concert_id BIGINT UNSIGNED NULL,
    contact_id BIGINT UNSIGNED NULL,
    source_json LONGTEXT NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY devis_factures_source (source_identity, source_table, source_key_hash),
    KEY devis_factures_concert (concert_id), KEY devis_factures_contact (contact_id),
    CONSTRAINT devis_factures_concert_fk FOREIGN KEY (concert_id)
        REFERENCES concerts_evenements(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT devis_factures_contact_fk FOREIGN KEY (contact_id)
        REFERENCES contacts_carnet_adresse(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    reference_annee SMALLINT UNSIGNED NULL,
    reference_sequence INT UNSIGNED NULL,
    reference_at DATETIME(6) NULL,
    source_snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY devis_factures_reference_sequence (type_document(8), reference_annee, reference_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devis_factures_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id BIGINT UNSIGNED NOT NULL,
    edited_by VARCHAR(254) NOT NULL,
    edited_at DATETIME(6) NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    PRIMARY KEY (id), KEY devis_factures_history (record_id, id),
    CONSTRAINT devis_factures_history_fk FOREIGN KEY (record_id)
        REFERENCES devis_factures_documents(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devis_factures_sequences (
    type_document VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    annee SMALLINT UNSIGNED NOT NULL,
    dernier_numero INT UNSIGNED NOT NULL,
    PRIMARY KEY (type_document, annee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concerts_calendar_settings (
    id TINYINT UNSIGNED NOT NULL,
    recipients_json TEXT NOT NULL,
    revision CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_by VARCHAR(254) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concerts_calendar_invitations (
    concert_id BIGINT UNSIGNED NOT NULL,
    event_uid VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    requested_by VARCHAR(254) NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (concert_id),
    UNIQUE KEY concerts_calendar_uid (event_uid),
    CONSTRAINT concerts_calendar_concert_fk FOREIGN KEY (concert_id)
        REFERENCES concerts_evenements (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devis_factures_deliveries (
    document_id BIGINT UNSIGNED NOT NULL,
    generation_status VARCHAR(16) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    template_copy_id VARCHAR(200) NULL,
    pdf_file_id VARCHAR(200) NULL,
    pdf_sha256 CHAR(64) NULL,
    generated_by VARCHAR(254) NOT NULL,
    generation_requested_at DATETIME(6) NOT NULL,
    generated_at DATETIME(6) NULL,
    mail_status VARCHAR(16) NULL,
    mail_json LONGTEXT NULL,
    sent_by VARCHAR(254) NULL,
    mail_requested_at DATETIME(6) NULL,
    mail_completed_at DATETIME(6) NULL,
    PRIMARY KEY (document_id),
    CONSTRAINT fk_delivery_document FOREIGN KEY (document_id)
        REFERENCES devis_factures_documents(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_rbac_roles (
 role_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 label VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_rbac_permissions (
 role_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 permission_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 allowed TINYINT UNSIGNED NOT NULL DEFAULT 0,
 updated_by VARCHAR(254) NOT NULL DEFAULT '',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (role_key,permission_key),
 CONSTRAINT Musicbandai_rbac_permissions_role FOREIGN KEY (role_key) REFERENCES Musicbandai_rbac_roles(role_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_rbac_principals (
 kind ENUM('account','invite') NOT NULL,
 principal VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 role_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_by VARCHAR(254) NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (kind,principal),
 CONSTRAINT Musicbandai_rbac_principals_role FOREIGN KEY (role_key) REFERENCES Musicbandai_rbac_roles(role_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE setlist_validations (
 source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 variant_key VARCHAR(64) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 publication_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
 variants_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
 validated_by VARCHAR(254) NOT NULL,
 validated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_notification_rules (
 event_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
 lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 21,
 fields_json TEXT NOT NULL,
 recipients_json TEXT NOT NULL,
 revision CHAR(32) CHARACTER SET ascii NOT NULL,
 updated_by VARCHAR(254) NOT NULL,
 updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_push_subscriptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 endpoint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 login VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 account_revision VARCHAR(64) CHARACTER SET ascii NOT NULL,
 subscription_json TEXT NOT NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_notification_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(40) CHARACTER SET ascii NOT NULL,
 source_key VARCHAR(180) CHARACTER SET ascii NOT NULL,
 path VARCHAR(255) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY Musicbandai_event_source(event_key,source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_push_deliveries (
 event_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL,
 requested_at DATETIME(6) NOT NULL,
 completed_at DATETIME(6) NULL,
 PRIMARY KEY(event_id,subscription_id),
 CONSTRAINT Musicbandai_push_event FOREIGN KEY(event_id) REFERENCES Musicbandai_notification_events(id) ON DELETE RESTRICT,
 CONSTRAINT Musicbandai_push_subscription FOREIGN KEY(subscription_id) REFERENCES Musicbandai_push_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE concerts_publications (
    concert_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    requested_by VARCHAR(254) NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    venue_id BIGINT UNSIGNED NULL,
    event_id BIGINT UNSIGNED NULL,
    http_status SMALLINT UNSIGNED NULL,
    PRIMARY KEY (concert_id,platform),
    CONSTRAINT concerts_publications_concert_fk FOREIGN KEY (concert_id)
        REFERENCES concerts_evenements (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE setlist_documents (
 document_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 content LONGTEXT NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE setlist_runtime (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 active TINYINT UNSIGNED NOT NULL DEFAULT 0,
 cleanup_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE setlist_songs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title TEXT NOT NULL, artist TEXT NOT NULL,
 duration_seconds INT UNSIGNED NULL, tempo INT UNSIGNED NULL,
 guitar TEXT NULL, musical_key TEXT NULL, energy INT UNSIGNED NULL,
 p5 TEXT NULL, seb_comments TEXT NULL,
 data_json LONGTEXT NOT NULL, source_json LONGTEXT NOT NULL,
 revision CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE setlist_song_links (
 source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 editor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 song_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(source_file,editor_id),
 CONSTRAINT setlist_link_publication FOREIGN KEY(source_file) REFERENCES Musicband_publications(source_file) ON DELETE RESTRICT,
 CONSTRAINT setlist_link_song FOREIGN KEY(song_id) REFERENCES setlist_songs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Musicbandai_audit_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 occurred_at DATETIME(6) NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
 module VARCHAR(64) NOT NULL,
 action VARCHAR(96) NOT NULL,
 outcome VARCHAR(24) NOT NULL,
 details TEXT NOT NULL,
 source_ip VARCHAR(45) NULL,
 location VARCHAR(160) NOT NULL,
 user_agent VARCHAR(512) NOT NULL,
 language VARCHAR(120) NOT NULL,
 method VARCHAR(12) NOT NULL,
 route VARCHAR(160) NOT NULL,
 PRIMARY KEY (id),
 KEY audit_time (occurred_at,id),
 KEY audit_login (action,actor,occurred_at),
 KEY audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_audit_settings (
 id TINYINT UNSIGNED NOT NULL,
 filters_json MEDIUMTEXT NOT NULL,
 last_purge_at DATETIME(6) NULL,
 last_purge_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_task_categories (
  id VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY cat_name (name),
  KEY cat_order (enabled, display_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NULL,
  category_id VARCHAR(32) NOT NULL,
  assignee VARCHAR(254) NULL,
  created_by VARCHAR(254) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  due_date DATE NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'a_faire',
  completed_by VARCHAR(254) NULL,
  completed_at DATETIME(6) NULL,
  updated_by VARCHAR(254) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  archived_at DATETIME(6) NULL,
  archived_by VARCHAR(254) NULL,
  PRIMARY KEY (id),
  KEY task_status_due (archived, status, due_date),
  KEY task_assignee (archived, assignee, status),
  KEY task_category (category_id),
  KEY task_created (archived, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE Musicbandai_contact_activities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id BIGINT UNSIGNED NOT NULL,
  user_login VARCHAR(254) NOT NULL,
  author_display_name VARCHAR(254) NOT NULL,
  channel VARCHAR(32) NOT NULL DEFAULT 'note',
  action VARCHAR(64) NOT NULL DEFAULT 'note',
  status VARCHAR(32) NOT NULL DEFAULT 'success',
  subject VARCHAR(255) NULL,
  comment LONGTEXT NULL,
  contacted_at DATETIME NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY activity_contact (contact_id, contacted_at),
  KEY activity_user (user_login),
  CONSTRAINT fk_contact_activities_contact FOREIGN KEY (contact_id) REFERENCES contacts_carnet_adresse(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses_suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    UNIQUE KEY expenses_supplier_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    member VARCHAR(254) NOT NULL,
    expense_date DATE NOT NULL,
    amount_cents BIGINT NOT NULL,
    justification TEXT NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    payment_method ENUM('advance','transfer') NOT NULL,
    reimbursed TINYINT(1) NOT NULL DEFAULT 0,
    reimbursement_date DATE NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    KEY expenses_period (expense_date, id),
    CONSTRAINT expenses_supplier_fk FOREIGN KEY (supplier_id) REFERENCES expenses_suppliers(id) ON DELETE RESTRICT,
    CONSTRAINT expenses_amount_positive CHECK (amount_cents > 0 AND amount_cents <= 99999999999),
    CONSTRAINT expenses_reimbursement CHECK ((payment_method='transfer' AND reimbursed=0 AND reimbursement_date IS NULL) OR (payment_method='advance' AND ((reimbursed=0 AND reimbursement_date IS NULL) OR (reimbursed=1 AND reimbursement_date >= expense_date))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    concert_id BIGINT UNSIGNED NULL,
    entry_date DATE NOT NULL,
    amount_cents BIGINT NOT NULL,
    description VARCHAR(500) NOT NULL,
    category ENUM('concert','membership','income','bank') NOT NULL,
    received TINYINT(1) NOT NULL DEFAULT 1,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY accounting_concert_once (concert_id),
    KEY accounting_period (entry_date,id),
    CONSTRAINT accounting_concert_fk FOREIGN KEY (concert_id) REFERENCES concerts_evenements(id) ON DELETE RESTRICT,
    CONSTRAINT accounting_amount_positive CHECK (amount_cents > 0 AND amount_cents <= 99999999999),
    CONSTRAINT accounting_source CHECK ((category='concert' AND concert_id IS NOT NULL AND deleted=0) OR (category<>'concert' AND concert_id IS NULL AND received=1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    opening_cents BIGINT NOT NULL DEFAULT 0,
    opening_date DATE NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT accounting_settings_singleton CHECK (id=1),
    CONSTRAINT accounting_opening_range CHECK (opening_cents BETWEEN -99999999999 AND 99999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounting_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity VARCHAR(32) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    actor VARCHAR(254) NOT NULL,
    changed_at DATETIME(6) NOT NULL,
    before_json LONGTEXT NOT NULL,
    after_json LONGTEXT NOT NULL,
    KEY accounting_history (entity,record_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO concerts_calendar_settings (id,recipients_json,revision,updated_by,updated_at)
VALUES (1,'[]','00000000000000000000000000000000','upgrade',UTC_TIMESTAMP(6));

INSERT IGNORE INTO setlist_runtime(id,active) VALUES (1,0);

INSERT IGNORE INTO Musicbandai_audit_settings(id,filters_json) VALUES (1,'[]');

INSERT IGNORE INTO Musicbandai_task_categories (id, name, display_order, enabled, created_at, updated_at) VALUES
('cat_graphisme', 'Graphisme', 1, 1, NOW(6), NOW(6)),
('cat_reseaux_sociaux', 'Réseaux sociaux', 2, 1, NOW(6), NOW(6)),
('cat_technique', 'Technique', 3, 1, NOW(6), NOW(6)),
('cat_achat', 'Achat', 4, 1, NOW(6), NOW(6)),
('cat_autre', 'Autre', 5, 1, NOW(6), NOW(6));
