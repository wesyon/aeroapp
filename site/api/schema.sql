-- =============================================================================
-- AERO Analytics — Database schema (MySQL / InnoDB, utf8mb4)
-- Mirrors three federal sources into one relational model.
--   Source A: FAC (Federal Audit Clearinghouse)  -> bulk-mirrored by audit_year
--   Source B: USAspending                        -> fetched on demand per UEI
--   Source C: SAM.gov Entity                      -> fetched on demand per UEI
-- Universal join key: UEI (12-char Unique Entity Identifier).
-- Scope: FY2022+ (every record has a real UEI).
--
-- Type normalization is applied at INGEST, not by the API:
--   FAC "Y"/"N" strings -> TINYINT(1) (1/0/NULL) ; string dates -> DATE ;
--   string years -> SMALLINT ; whole-dollar amounts -> BIGINT.
--
-- Apply order matters (parents before children). This file drops & recreates
-- everything so it can be re-run cleanly for local dev (`--init`).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS sync_log;
DROP TABLE IF EXISTS api_quota_obs;
DROP TABLE IF EXISTS state_uei;
DROP TABLE IF EXISTS entity_related_uei;
DROP TABLE IF EXISTS aero_score;
DROP TABLE IF EXISTS sam_acquisition_subaward;
DROP TABLE IF EXISTS sam_assistance_subaward;
DROP TABLE IF EXISTS sam_exclusion;
DROP TABLE IF EXISTS federal_agency;
DROP TABLE IF EXISTS assistance_listing;
DROP TABLE IF EXISTS sam_business_type;
DROP TABLE IF EXISTS sam_entity_naics;
DROP TABLE IF EXISTS sam_entity;
DROP TABLE IF EXISTS usa_award_outlay_month;
DROP TABLE IF EXISTS usa_award_txn_month;
DROP TABLE IF EXISTS usa_award_cfda;
DROP TABLE IF EXISTS usa_award;
DROP TABLE IF EXISTS usa_recipient_business_type;
DROP TABLE IF EXISTS usa_recipient;
DROP TABLE IF EXISTS fac_resubmission;
DROP TABLE IF EXISTS fac_secondary_auditors;
DROP TABLE IF EXISTS fac_additional_eins;
DROP TABLE IF EXISTS fac_additional_ueis;
DROP TABLE IF EXISTS fac_notes_to_sefa;
DROP TABLE IF EXISTS fac_passthrough;
DROP TABLE IF EXISTS fac_finding_extract;
DROP TABLE IF EXISTS fac_corrective_action_plans;
DROP TABLE IF EXISTS fac_findings_text;
DROP TABLE IF EXISTS fac_finding_awards;
DROP TABLE IF EXISTS fac_findings;
DROP TABLE IF EXISTS fac_federal_awards;
DROP TABLE IF EXISTS fac_general;
DROP TABLE IF EXISTS entity;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- GROUP 0 — Cross-source hub
-- =============================================================================

-- Conformed grantee dimension. One row per UEI; ties FAC, USAspending, SAM.
CREATE TABLE entity (
  uei          CHAR(12)     NOT NULL,
  legal_name   VARCHAR(255) NULL,          -- SAM legalBusinessName, fallback FAC auditee_name
  -- Denormalized FAC-derived recipient directory (maintained by api/sync/build_entity_directory.php,
  -- INDEPENDENT of the risk score). This makes `entity` the search/profile backbone, so a paused /
  -- stale / failed aero_score never breaks Search or 404s a profile. NULL identity = no active FAC
  -- presence (Search lists WHERE latest_audit_year IS NOT NULL).
  display_name      VARCHAR(255) NULL,     -- latest active audit's auditee_name
  entity_type       VARCHAR(50)  NULL,     -- latest active audit's entity_type
  audit_count       SMALLINT     NULL,     -- distinct active audit years
  latest_audit_year SMALLINT     NULL,     -- max active audit year
  federal_latest    BIGINT       NULL,     -- latest active audit's total_amount_expended
  is_hhs            TINYINT(1)   NOT NULL DEFAULT 0,  -- has any ALN-93 (HHS) award
  ein          VARCHAR(20)  NULL,
  state        CHAR(2)      NULL,
  has_fac      TINYINT(1)   NOT NULL DEFAULT 0,
  has_usa      TINYINT(1)   NOT NULL DEFAULT 0,
  has_sam      TINYINT(1)   NOT NULL DEFAULT 0,
  has_addl     TINYINT(1)   NOT NULL DEFAULT 0,   -- covered by an audit as an additional UEI (fac_additional_ueis), not a primary auditee
  first_seen   DATETIME     NULL,         -- earliest FAC-accepted report (set by sync_fac, never moves)
  last_seen    DATETIME     NULL,         -- refreshed by the SAM entity seed
  PRIMARY KEY (uei),
  KEY idx_entity_state (state),
  KEY idx_entity_name (display_name),
  KEY idx_entity_type (entity_type),
  KEY idx_entity_year (latest_audit_year),
  KEY idx_entity_hhs (is_hhs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP A — FAC, hub-and-spoke on report_id
-- =============================================================================

-- One row per audit submission (the "general information" table).
CREATE TABLE fac_general (
  report_id                                    VARCHAR(40) NOT NULL,
  audit_year                                   SMALLINT    NULL,
  -- Auditee identity
  auditee_uei                                  CHAR(12)    NULL,   -- soft FK -> entity(uei)
  auditee_ein                                  VARCHAR(20) NULL,
  auditee_name                                 VARCHAR(255) NULL,
  auditee_address_line_1                       VARCHAR(255) NULL,
  auditee_city                                 VARCHAR(100) NULL,
  auditee_state                                CHAR(2)     NULL,
  auditee_zip                                  VARCHAR(10) NULL,
  auditee_email                                VARCHAR(255) NULL,
  auditee_phone                                VARCHAR(30) NULL,
  auditee_contact_name                         VARCHAR(255) NULL,
  auditee_contact_title                        VARCHAR(255) NULL,
  auditee_certify_name                         VARCHAR(255) NULL,
  auditee_certify_title                        VARCHAR(255) NULL,
  entity_type                                  VARCHAR(50) NULL,
  -- Period / type
  fy_start_date                                DATE        NULL,
  fy_end_date                                  DATE        NULL,
  audit_period_covered                         VARCHAR(20) NULL,
  number_months                                SMALLINT    NULL,
  audit_type                                   VARCHAR(50) NULL,
  type_audit_code                              VARCHAR(20) NULL,
  gaap_results                                 VARCHAR(100) NULL,
  -- worst-first opinion category, derived + indexed for the Modified Opinions dashboard
  -- (mirrors migrations/2026-07-16_fac_general_gaap_cat.sql; 'qualified' sits inside the
  -- legacy word 'unqualified', hence the guard)
  gaap_cat VARCHAR(12) GENERATED ALWAYS AS (CASE
      WHEN gaap_results LIKE '%adverse%' THEN 'adverse'
      WHEN gaap_results LIKE '%disclaimer%' THEN 'disclaimer'
      WHEN gaap_results LIKE '%qualified%' AND gaap_results NOT LIKE '%unqualified%' THEN 'qualified'
      ELSE 'unmodified' END) STORED,
  data_source                                  VARCHAR(20) NULL,
  -- Auditor
  auditor_firm_name                            VARCHAR(255) NULL,
  auditor_ein                                  VARCHAR(20) NULL,
  auditor_email                                VARCHAR(255) NULL,
  auditor_phone                                VARCHAR(30) NULL,
  auditor_contact_name                         VARCHAR(255) NULL,
  auditor_contact_title                        VARCHAR(255) NULL,
  auditor_certify_name                         VARCHAR(255) NULL,
  auditor_certify_title                        VARCHAR(255) NULL,
  auditor_address_line_1                       VARCHAR(255) NULL,
  auditor_city                                 VARCHAR(100) NULL,
  auditor_state                                VARCHAR(10) NULL,
  auditor_zip                                  VARCHAR(10) NULL,
  auditor_country                              VARCHAR(50) NULL,
  auditor_foreign_address                      VARCHAR(255) NULL,
  -- Money / risk
  dollar_threshold                             BIGINT      NULL,
  total_amount_expended                        BIGINT      NULL,
  is_low_risk_auditee                          TINYINT(1)  NULL,
  cognizant_agency                             VARCHAR(2)  NULL,
  oversight_agency                             VARCHAR(2)  NULL,
  agencies_with_prior_findings                 VARCHAR(255) NULL,
  -- Result flags
  is_going_concern_included                    TINYINT(1)  NULL,
  is_internal_control_deficiency_disclosed     TINYINT(1)  NULL,
  is_internal_control_material_weakness_disclosed TINYINT(1) NULL,
  is_material_noncompliance_disclosed          TINYINT(1)  NULL,
  is_aicpa_audit_guide_included                TINYINT(1)  NULL,
  is_additional_ueis                           TINYINT(1)  NULL,
  is_multiple_eins                             TINYINT(1)  NULL,
  is_secondary_auditors                        TINYINT(1)  NULL,
  is_public                                    TINYINT(1)  NULL,
  -- Special-purpose framework
  is_sp_framework_required                     TINYINT(1)  NULL,
  sp_framework_basis                           VARCHAR(255) NULL,
  sp_framework_opinions                        VARCHAR(255) NULL,
  -- Workflow dates
  fac_accepted_date                            DATE        NULL,
  submitted_date                               DATE        NULL,
  date_created                                 DATETIME    NULL,
  ready_for_certification_date                 DATE        NULL,
  auditee_certified_date                       DATE        NULL,
  auditor_certified_date                       DATE        NULL,
  resubmission_status                          VARCHAR(50) NULL,
  resubmission_version                         INT         NULL,
  -- 1 = most-recently-accepted report for (auditee_uei, audit_year); 0 = superseded
  -- by a resubmission. Maintained by sync_fac.php; aggregates filter on it so
  -- resubmitted audits aren't double-counted.
  is_active                                    TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (report_id),
  KEY idx_general_uei (auditee_uei),
  KEY idx_general_year (audit_year),
  KEY idx_general_state (auditee_state),
  KEY idx_fg_gaap_cat (gaap_cat, is_active, audit_year),
  CONSTRAINT fk_general_entity FOREIGN KEY (auditee_uei)
      REFERENCES entity (uei) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Federal awards covered by an audit. ALN = federal_agency_prefix + extension.
CREATE TABLE fac_federal_awards (
  report_id                       VARCHAR(40) NOT NULL,
  award_reference                 VARCHAR(20) NOT NULL,
  federal_agency_prefix           VARCHAR(4)  NULL,   -- usually 2-digit agency code; widened for edge cases
  federal_award_extension         VARCHAR(20) NULL,   -- 3-digit extension, or "RD"/"U##"/longer filer values
  aln                             VARCHAR(24) NULL,   -- derived prefix.extension, set at ingest
  federal_program_name            VARCHAR(500) NULL,
  amount_expended                 BIGINT      NULL,
  federal_program_total           BIGINT      NULL,
  cluster_name                    VARCHAR(255) NULL,
  other_cluster_name              VARCHAR(255) NULL,
  state_cluster_name              VARCHAR(255) NULL,
  cluster_total                   BIGINT      NULL,
  audit_report_type               VARCHAR(50) NULL,
  findings_count                  INT         NULL,
  additional_award_identification VARCHAR(500) NULL,
  is_direct                       TINYINT(1)  NULL,
  is_loan                         TINYINT(1)  NULL,
  is_major                        TINYINT(1)  NULL,
  is_passthrough_award            TINYINT(1)  NULL,
  loan_balance                    BIGINT      NULL,
  passthrough_amount              BIGINT      NULL,
  -- denormalized for direct joins/filters
  auditee_uei                     CHAR(12)    NULL,
  audit_year                      SMALLINT    NULL,
  fac_accepted_date               DATE        NULL,
  PRIMARY KEY (report_id, award_reference),
  KEY idx_awards_agency (federal_agency_prefix),
  KEY idx_awards_aln (aln),
  -- per-major-program compliance opinion (U/Q/A/D) for the Modified Opinions dashboard;
  -- audit_report_type leads (Q/A/D ~33k of ~2.5M rows). Mirrors
  -- migrations/2026-07-16_fac_federal_awards_opinion_index.sql.
  KEY idx_fa_opinion (audit_report_type, is_major, aln, report_id, amount_expended),
  -- (uei, prefix) lets the recipients-by-agency EXISTS run as an indexed semi-join
  -- instead of materializing ~1.3M award rows (recipient search ~14s -> <1s). Its
  -- auditee_uei prefix also serves every plain auditee_uei= lookup, so no standalone
  -- idx_awards_uei is needed (dropped 2026-06-12 to reclaim ~100 MB of index).
  KEY idx_awards_uei_agency (auditee_uei, federal_agency_prefix),
  KEY idx_awards_year (audit_year),
  CONSTRAINT fk_awards_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per audit finding (attributes are reported at the finding level).
-- A finding can apply to several awards; that mapping lives in fac_finding_awards.
CREATE TABLE fac_findings (
  report_id                 VARCHAR(40) NOT NULL,
  reference_number          VARCHAR(20) NOT NULL,
  type_requirement          VARCHAR(40) NULL,
  is_material_weakness      TINYINT(1)  NULL,
  is_significant_deficiency TINYINT(1)  NULL,
  is_modified_opinion       TINYINT(1)  NULL,
  is_other_findings         TINYINT(1)  NULL,
  is_other_matters          TINYINT(1)  NULL,
  is_questioned_costs       TINYINT(1)  NULL,
  is_repeat_finding         TINYINT(1)  NULL,
  prior_finding_ref_numbers VARCHAR(255) NULL,
  auditee_uei               CHAR(12)    NULL,
  audit_year                SMALLINT    NULL,
  fac_accepted_date         DATE        NULL,
  PRIMARY KEY (report_id, reference_number),
  KEY idx_findings_type (type_requirement),
  KEY idx_findings_uei (auditee_uei),
  KEY idx_findings_year (audit_year),
  CONSTRAINT fk_findings_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bridge: which awards each finding applies to (many-to-many). The FAC findings
-- endpoint returns one row per (finding, award); the finding's attributes are
-- identical across those rows, so they live in fac_findings and the award links
-- live here. (report_id, award_reference) soft-joins to fac_federal_awards.
CREATE TABLE fac_finding_awards (
  report_id         VARCHAR(40) NOT NULL,
  reference_number  VARCHAR(20) NOT NULL,
  award_reference   VARCHAR(20) NOT NULL,
  auditee_uei       CHAR(12)    NULL,
  audit_year        SMALLINT    NULL,
  fac_accepted_date DATE        NULL,
  -- denormalized from fac_federal_awards (set by sync) to power fast by-agency aggregation
  federal_agency_prefix VARCHAR(4) NULL,
  PRIMARY KEY (report_id, reference_number, award_reference),
  KEY idx_fawards_award (report_id, award_reference),
  -- grantee profile filters the bridge on auditee_uei in three queries per view
  KEY idx_fawards_uei (auditee_uei),
  -- covering index for the by-agency aggregation: GROUP BY prefix + DISTINCT
  -- (report_id, reference_number) is satisfied index-only (no table touch).
  KEY idx_fawards_prefix_finding (federal_agency_prefix, report_id, reference_number),
  CONSTRAINT fk_fawards_finding FOREIGN KEY (report_id, reference_number)
      REFERENCES fac_findings (report_id, reference_number)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Narrative text for each finding (1:1 with fac_findings).
CREATE TABLE fac_findings_text (
  report_id              VARCHAR(40) NOT NULL,
  finding_ref_number     VARCHAR(20) NOT NULL,
  finding_text           MEDIUMTEXT  NULL,
  contains_chart_or_table TINYINT(1) NULL,
  auditee_uei            CHAR(12)    NULL,
  audit_year             SMALLINT    NULL,
  fac_accepted_date      DATE        NULL,
  PRIMARY KEY (report_id, finding_ref_number),
  CONSTRAINT fk_findtext_finding FOREIGN KEY (report_id, finding_ref_number)
      REFERENCES fac_findings (report_id, reference_number)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structured fields unpacked from fac_findings_text by api/sync/parse_findings.php
-- (1:1 with the text). Splits the GAGAS finding elements and extracts scalars the
-- FAC API does not expose — most importantly the questioned-cost dollar amounts.
-- Re-runnable + versioned (parser_version); add columns + bump the version + re-run
-- as the extraction grows. Created by the script too (CREATE TABLE IF NOT EXISTS).
CREATE TABLE fac_finding_extract (
  report_id            VARCHAR(40) NOT NULL,
  finding_ref_number   VARCHAR(20) NOT NULL,
  auditee_uei          CHAR(12)    NULL,
  audit_year           SMALLINT    NULL,
  -- parsed GAGAS elements (NULL = label not found); 'condition' is reserved → renamed
  criteria             MEDIUMTEXT  NULL,
  finding_condition    MEDIUMTEXT  NULL,
  cause                MEDIUMTEXT  NULL,
  effect               MEDIUMTEXT  NULL,
  questioned_costs     MEDIUMTEXT  NULL,
  recommendation       MEDIUMTEXT  NULL,
  context              MEDIUMTEXT  NULL,
  auditee_response     MEDIUMTEXT  NULL,   -- "Views of Responsible Officials"
  -- extracted scalars
  qc_known             BIGINT      NULL,   -- known questioned costs ($)
  qc_likely            BIGINT      NULL,   -- likely/projected questioned costs ($)
  qc_amount            BIGINT      NULL,   -- headline QC figure (known, else QC-tied $)
  qc_stated_zero       TINYINT(1)  NULL,   -- QC section explicitly None / $0
  qc_basis             VARCHAR(10) NULL,   -- how qc_amount was derived: known|generic|flagged|likely|zero|unknown|none|suspect (score uses known+generic+flagged)
  sample_size          INT         NULL,   -- "of the X transactions tested…"
  sections_found       TINYINT     NULL,   -- count of GAGAS labels matched (0-8)
  text_len             INT         NULL,
  parser_version       SMALLINT    NOT NULL,
  parsed_at            DATETIME    NOT NULL,
  PRIMARY KEY (report_id, finding_ref_number),
  KEY idx_fext_uei (auditee_uei),
  KEY idx_fext_year (audit_year),
  KEY idx_fext_qc (qc_amount),
  KEY idx_fext_ver (parser_version),
  -- covering join for the Findings dashboard (read qc_amount without touching the MEDIUMTEXT
  -- row) — see migrations/2026-07-16_fac_finding_extract_join_index.sql
  KEY idx_fext_join_qc (report_id, finding_ref_number, qc_amount),
  CONSTRAINT fk_fext_text FOREIGN KEY (report_id, finding_ref_number)
      REFERENCES fac_findings_text (report_id, finding_ref_number)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrective action plan for each finding (1:1 with fac_findings).
CREATE TABLE fac_corrective_action_plans (
  report_id              VARCHAR(40) NOT NULL,
  finding_ref_number     VARCHAR(20) NOT NULL,
  planned_action         MEDIUMTEXT  NULL,
  contains_chart_or_table TINYINT(1) NULL,
  auditee_uei            CHAR(12)    NULL,
  audit_year             SMALLINT    NULL,
  fac_accepted_date      DATE        NULL,
  PRIMARY KEY (report_id, finding_ref_number),
  CONSTRAINT fk_cap_finding FOREIGN KEY (report_id, finding_ref_number)
      REFERENCES fac_findings (report_id, reference_number)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passthrough entities for an award (0..N per award). Surrogate PK.
CREATE TABLE fac_passthrough (
  id                BIGINT      NOT NULL AUTO_INCREMENT,
  report_id         VARCHAR(40) NOT NULL,
  award_reference   VARCHAR(20) NOT NULL,
  passthrough_id    VARCHAR(4000) NULL,   -- filers occasionally cram long text here
  passthrough_name  VARCHAR(500) NULL,
  auditee_uei       CHAR(12)    NULL,
  audit_year        SMALLINT    NULL,
  fac_accepted_date DATE        NULL,
  -- No natural unique key: idempotency comes from delete-by-scope before insert,
  -- and passthrough_id can be too long to index.
  PRIMARY KEY (id),
  KEY idx_passthrough_award (report_id, award_reference),
  CONSTRAINT fk_passthrough_award FOREIGN KEY (report_id, award_reference)
      REFERENCES fac_federal_awards (report_id, award_reference)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes to the Schedule of Expenditures of Federal Awards (0..N per report). Surrogate PK.
CREATE TABLE fac_notes_to_sefa (
  id                  BIGINT      NOT NULL AUTO_INCREMENT,
  report_id           VARCHAR(40) NOT NULL,
  title               VARCHAR(2000) NULL,   -- filers cram long text here (max seen ~1630)
  content             MEDIUMTEXT  NULL,
  accounting_policies MEDIUMTEXT  NULL,
  is_minimis_rate_used TINYINT(1) NULL,
  rate_explained      MEDIUMTEXT  NULL,
  contains_chart_or_table TINYINT(1) NULL,
  auditee_uei         CHAR(12)    NULL,
  audit_year          SMALLINT    NULL,
  fac_accepted_date   DATE        NULL,
  PRIMARY KEY (id),
  KEY idx_notes_report (report_id),
  CONSTRAINT fk_notes_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional UEIs reported by a multi-UEI auditee.
CREATE TABLE fac_additional_ueis (
  report_id         VARCHAR(40) NOT NULL,
  additional_uei    CHAR(12)    NOT NULL,
  auditee_uei       CHAR(12)    NULL,
  audit_year        SMALLINT    NULL,
  fac_accepted_date DATE        NULL,
  PRIMARY KEY (report_id, additional_uei),
  KEY idx_addueis_auditee (auditee_uei),      -- by-parent lookup (WHERE auditee_uei IN ...)
  KEY idx_addueis_member (additional_uei),     -- by-member lookup (additional_uei = / LIKE / IN ...)
  CONSTRAINT fk_addueis_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional EINs reported by a multi-EIN auditee.
CREATE TABLE fac_additional_eins (
  report_id         VARCHAR(40) NOT NULL,
  additional_ein    VARCHAR(20) NOT NULL,
  auditee_uei       CHAR(12)    NULL,
  audit_year        SMALLINT    NULL,
  fac_accepted_date DATE        NULL,
  PRIMARY KEY (report_id, additional_ein),
  CONSTRAINT fk_addeins_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secondary auditors (0..N per report). Surrogate PK.
CREATE TABLE fac_secondary_auditors (
  id                BIGINT      NOT NULL AUTO_INCREMENT,
  report_id         VARCHAR(40) NOT NULL,
  auditor_name      VARCHAR(255) NULL,
  auditor_ein       VARCHAR(20) NULL,
  address_street    VARCHAR(255) NULL,
  address_city      VARCHAR(100) NULL,
  address_state     VARCHAR(10) NULL,
  address_zipcode   VARCHAR(10) NULL,
  contact_name      VARCHAR(255) NULL,
  contact_title     VARCHAR(255) NULL,
  contact_email     VARCHAR(255) NULL,
  contact_phone     VARCHAR(30) NULL,
  auditee_uei       CHAR(12)    NULL,
  audit_year        SMALLINT    NULL,
  fac_accepted_date DATE        NULL,
  PRIMARY KEY (id),
  KEY idx_secaud_report (report_id),
  CONSTRAINT fk_secaud_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resubmission lineage (1:1 with a report). Adds the prev/next chain that
-- fac_general only hints at via resubmission_version/status.
CREATE TABLE fac_resubmission (
  report_id                VARCHAR(40) NOT NULL,
  version                  INT         NULL,   -- 0 = original submission
  status                   VARCHAR(30) NULL,   -- most_recent | deprecated_by_resubmission | unknown
  previous_report_id       VARCHAR(40) NULL,   -- soft link (may be outside ingest scope)
  next_report_id           VARCHAR(40) NULL,   -- soft link
  original_submission_date DATE        NULL,
  auditee_uei              CHAR(12)    NULL,
  audit_year               SMALLINT    NULL,
  fac_accepted_date        DATE        NULL,
  PRIMARY KEY (report_id),
  KEY idx_resub_prev (previous_report_id),
  KEY idx_resub_next (next_report_id),
  KEY idx_resub_status (status),
  CONSTRAINT fk_resub_general FOREIGN KEY (report_id)
      REFERENCES fac_general (report_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP B — USAspending (on-demand per UEI, cached)
-- =============================================================================

CREATE TABLE usa_recipient (
  uei                  CHAR(12)    NOT NULL,
  recipient_id         VARCHAR(64) NULL,   -- "{hash}-R/C/P" id used for detail calls
  recipient_hash       VARCHAR(64) NULL,
  duns                 VARCHAR(13) NULL,
  name                 VARCHAR(255) NULL,
  alternate_names      JSON        NULL,
  recipient_level      CHAR(1)     NULL,   -- P / C / R
  parent_uei           CHAR(12)    NULL,
  parent_duns          VARCHAR(13) NULL,
  parent_id            VARCHAR(64) NULL,
  parent_name          VARCHAR(255) NULL,
  -- location
  location_address_line1 VARCHAR(255) NULL,
  location_address_line2 VARCHAR(255) NULL,
  location_city        VARCHAR(100) NULL,
  location_county      VARCHAR(100) NULL,
  location_state_code  VARCHAR(3)  NULL,
  location_zip         VARCHAR(5)  NULL,
  location_zip4        VARCHAR(10) NULL,
  location_congressional_code VARCHAR(5) NULL,
  location_country_code VARCHAR(3) NULL,
  location_country_name VARCHAR(100) NULL,
  -- financials
  total_transaction_amount        DECIMAL(18,2) NULL,
  total_transactions              BIGINT        NULL,
  total_face_value_loan_amount    DECIMAL(18,2) NULL,
  total_face_value_loan_transactions BIGINT     NULL,
  -- 1 = a sync page cap was hit; stored awards are the largest, not the complete set
  sync_truncated       TINYINT(1)  NOT NULL DEFAULT 0,
  last_synced          DATETIME    NULL,
  PRIMARY KEY (uei),
  KEY idx_usarec_parent (parent_uei),
  KEY idx_usarec_state (location_state_code),
  CONSTRAINT fk_usarec_entity FOREIGN KEY (uei)
      REFERENCES entity (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business categories for a recipient (0..N).
CREATE TABLE usa_recipient_business_type (
  uei           CHAR(12)    NOT NULL,
  business_type VARCHAR(80) NOT NULL,   -- e.g. small_business, nonprofit, higher_education
  PRIMARY KEY (uei, business_type),
  CONSTRAINT fk_usabtype_recipient FOREIGN KEY (uei)
      REFERENCES usa_recipient (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usa_award (
  award_id                VARCHAR(64) NOT NULL,  -- generated_unique_award_id
  internal_id             VARCHAR(40) NULL,      -- numeric "id" for detail calls
  category                VARCHAR(20) NULL,      -- grant | contract | loan | direct_payment | idv
  award_type_code         VARCHAR(10) NULL,      -- "type", e.g. 02
  award_type_description  VARCHAR(100) NULL,
  record_type             INT         NULL,
  description             MEDIUMTEXT  NULL,
  fain                    VARCHAR(40) NULL,
  uri                     VARCHAR(70) NULL,
  piid                    VARCHAR(60) NULL,
  date_signed             DATE        NULL,
  -- recipient
  recipient_uei           CHAR(12)    NULL,
  recipient_name          VARCHAR(255) NULL,
  recipient_hash          VARCHAR(64) NULL,
  parent_recipient_uei    CHAR(12)    NULL,
  parent_recipient_name   VARCHAR(255) NULL,
  -- agencies
  awarding_toptier_agency VARCHAR(255) NULL,
  awarding_subtier_agency VARCHAR(255) NULL,
  awarding_office_name    VARCHAR(255) NULL,
  awarding_agency_id      INT         NULL,
  funding_toptier_agency  VARCHAR(255) NULL,
  funding_subtier_agency  VARCHAR(255) NULL,
  funding_office_name     VARCHAR(255) NULL,
  funding_agency_id       INT         NULL,
  -- money
  total_obligation        DECIMAL(18,2) NULL,
  total_outlay            DECIMAL(18,2) NULL,
  total_funding           DECIMAL(18,2) NULL,
  total_subsidy_cost      DECIMAL(18,2) NULL,
  total_loan_value        DECIMAL(18,2) NULL,
  base_and_all_options    DECIMAL(18,2) NULL,
  base_exercised_options  DECIMAL(18,2) NULL,
  subaward_count          INT         NULL,
  total_subaward_amount   DECIMAL(18,2) NULL,
  -- period of performance
  period_start_date       DATE        NULL,
  period_end_date         DATE        NULL,
  period_last_modified_date DATE      NULL,
  -- place of performance
  pop_city                VARCHAR(100) NULL,
  pop_county              VARCHAR(100) NULL,
  pop_state_code          VARCHAR(3)  NULL,
  pop_state_name          VARCHAR(100) NULL,
  pop_zip5                VARCHAR(5)  NULL,
  pop_congressional_code  VARCHAR(5)  NULL,
  pop_country_name        VARCHAR(100) NULL,
  last_synced             DATETIME    NULL,
  outlay_synced           DATETIME    NULL,  -- last File C outlay-months pull for this award (see usa_award_outlay_month)
  PRIMARY KEY (award_id),
  KEY idx_usaaward_uei (recipient_uei),
  KEY idx_usaaward_category (category),
  KEY idx_usaaward_outlaysync (outlay_synced),
  CONSTRAINT fk_usaaward_recipient FOREIGN KEY (recipient_uei)
      REFERENCES usa_recipient (uei) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An award can fund multiple programs (cfda_info is a list). One row per ALN.
CREATE TABLE usa_award_cfda (
  award_id                   VARCHAR(64) NOT NULL,
  cfda_number                VARCHAR(24) NOT NULL,  -- ALN; joins to assistance_listing
  cfda_title                 VARCHAR(255) NULL,
  cfda_popular_name          VARCHAR(255) NULL,
  cfda_federal_agency        VARCHAR(255) NULL,
  federal_action_obligation_amount DECIMAL(18,2) NULL,
  non_federal_funding_amount DECIMAL(18,2) NULL,
  total_funding_amount       DECIMAL(18,2) NULL,
  PRIMARY KEY (award_id, cfda_number),
  KEY idx_awardcfda_aln (cfda_number),
  -- covering index for the per-request CFDA-title lookup (SELECT cfda_number, MAX(cfda_title)
  -- ... GROUP BY cfda_number): without cfda_title in the index, MAX() does a row lookup per
  -- group and the GROUP BY over ~1M rows costs ~5s; the covering index makes it a ~40ms loose scan.
  KEY idx_awardcfda_title (cfda_number, cfda_title),
  CONSTRAINT fk_awardcfda_award FOREIGN KEY (award_id)
      REFERENCES usa_award (award_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-award obligations summed by calendar month of the transaction action_date AND CFDA, so the UI
-- can bucket obligations into any fiscal year at view time (matching USAspending.gov's per-transaction
-- FY split) and compare obligations by PROGRAM year-over-year. Populated by sync_usa_txns.php.
-- NO foreign key to usa_award (deliberate, like usa_award_outlay_month): sync_usa.php refreshes a
-- recipient by DELETE + reinsert, and an ON DELETE CASCADE here silently wiped the refreshed
-- recipients' obligation months nightly (mirrors the 2026-07-10 outlay-month incident). Orphaned
-- month rows are harmless — every reader joins through usa_award.
CREATE TABLE usa_award_txn_month (
  award_id    VARCHAR(64)   NOT NULL,
  cfda        VARCHAR(24)   NOT NULL DEFAULT '',   -- transaction Assistance Listing (CFDA); '' if none
  ym          DATE          NOT NULL,              -- first day of the action_date month
  obligation  DECIMAL(18,2) NOT NULL,              -- sum of federal_action_obligation that month, for that CFDA
  PRIMARY KEY (award_id, ym, cfda),
  KEY idx_txnmonth_cfda (cfda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-award OUTLAYS summed by calendar month, reconstructed from File C (Account Breakdown by Award).
-- Outlays are NOT transaction-level (USAspending exposes none per transaction); they exist only as
-- account-level File C figures that are CUMULATIVE within the FEDERAL fiscal year and reset Oct 1.
-- sync_usa_outlays.php pulls each award's /awards/{id}/funding/ rows, sums gross_outlay_amount by
-- (federal FY, reporting period), differences consecutive periods within each FY to recover the
-- calendar-month outlay, and stores that delta here — so the UI can bucket outlays into any fiscal
-- year at view time (entity FYE or federal Sep-30), just like obligations. Deltas may be negative.
-- NO foreign key to usa_award (deliberate, like usa_award_txn_month): sync_usa.php refreshes a
-- recipient by DELETE + reinsert, and an ON DELETE CASCADE here silently destroyed the refreshed
-- recipients' outlay months every night (2026-07-10: 77k awards wiped by one nightly). Orphaned
-- month rows are harmless — every reader joins through usa_award.
CREATE TABLE usa_award_outlay_month (
  award_id    VARCHAR(64)   NOT NULL,
  ym          DATE          NOT NULL,              -- first day of the calendar month the outlay fell in
  outlay      DECIMAL(18,2) NOT NULL,              -- gross outlay that calendar month (period-over-period delta; may be negative)
  PRIMARY KEY (award_id, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP C — SAM.gov Entity (on-demand per UEI, cached)
-- =============================================================================

CREATE TABLE sam_entity (
  uei                          CHAR(12)    NOT NULL,  -- ueiSAM
  legal_business_name          VARCHAR(255) NULL,
  dba_name                     VARCHAR(255) NULL,
  cage_code                    VARCHAR(10) NULL,
  registration_status          VARCHAR(20) NULL,
  uei_status                   VARCHAR(20) NULL,
  registration_date            DATE        NULL,
  registration_expiration_date DATE        NULL,
  activation_date              DATE        NULL,
  last_update_date             DATE        NULL,
  uei_creation_date            DATE        NULL,
  purpose_of_registration_code VARCHAR(10) NULL,
  purpose_of_registration_desc VARCHAR(100) NULL,
  exclusion_status_flag        CHAR(1)     NULL,
  entity_structure             VARCHAR(50) NULL,
  state_of_incorporation       VARCHAR(2)  NULL,
  country_of_incorporation     VARCHAR(3)  NULL,
  physical_address_line1       VARCHAR(255) NULL,
  physical_address_city        VARCHAR(100) NULL,
  physical_address_state       VARCHAR(10) NULL,
  physical_address_zip         VARCHAR(10) NULL,
  physical_address_country     VARCHAR(3)  NULL,
  congressional_district       VARCHAR(10) NULL,
  entity_start_date            DATE        NULL,
  fiscal_year_end_close_date   VARCHAR(10) NULL,
  last_synced                  DATETIME    NULL,
  PRIMARY KEY (uei),
  KEY idx_sament_status (registration_status),
  CONSTRAINT fk_sament_entity FOREIGN KEY (uei)
      REFERENCES entity (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sam_entity_naics (
  uei              CHAR(12)    NOT NULL,
  naics_code       VARCHAR(6)  NOT NULL,
  naics_description VARCHAR(255) NULL,
  is_primary       TINYINT(1)  NULL,
  PRIMARY KEY (uei, naics_code),
  CONSTRAINT fk_naics_sament FOREIGN KEY (uei)
      REFERENCES sam_entity (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sam_business_type (
  uei       CHAR(12)    NOT NULL,
  type_code VARCHAR(10) NOT NULL,
  type_desc VARCHAR(255) NULL,
  PRIMARY KEY (uei, type_code),
  CONSTRAINT fk_btype_sament FOREIGN KEY (uei)
      REFERENCES sam_entity (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP C2 — SAM.gov Exclusions & Subawards (on-demand, cached)
-- Soft UEI links only: these are GLOBAL universes (any party), not limited to
-- our grantees, so they are not hard children of entity.
-- =============================================================================

-- Debarment / suspension records (SAM Exclusions API, ~167k records).
-- Flattened from the nested v4 response; one row per excluded party+action.
CREATE TABLE sam_exclusion (
  id                    BIGINT      NOT NULL AUTO_INCREMENT,
  uei_sam               CHAR(12)    NULL,        -- soft link to entity(uei); NULL for individuals
  cage_code             VARCHAR(10) NULL,
  npi                   VARCHAR(15) NULL,
  classification_type   VARCHAR(40) NULL,        -- Individual | Firm | Vessel | Special Entity Designation
  exclusion_type        VARCHAR(60) NULL,
  exclusion_program     VARCHAR(40) NULL,        -- Reciprocal | NonProcurement | Procurement
  excluding_agency_code VARCHAR(20) NULL,
  excluding_agency_name VARCHAR(255) NULL,
  entity_name           VARCHAR(255) NULL,
  prefix                VARCHAR(20) NULL,
  first_name            VARCHAR(100) NULL,
  middle_name           VARCHAR(100) NULL,
  last_name             VARCHAR(100) NULL,
  suffix                VARCHAR(50) NULL,
  create_date           DATE        NULL,
  activate_date         DATE        NULL,
  termination_date      DATE        NULL,        -- "Indefinite" normalized to NULL at ingest
  update_date           DATE        NULL,
  termination_type      VARCHAR(40) NULL,
  record_status         VARCHAR(20) NULL,        -- Active | Inactive
  is_fascsa_order       VARCHAR(10) NULL,
  city                  VARCHAR(100) NULL,
  state_or_province     VARCHAR(80) NULL,
  zip_code              VARCHAR(30) NULL,
  country_code          VARCHAR(3)  NULL,
  last_synced           DATETIME    NULL,
  -- No natural unique key: an entity legitimately has many exclusion records (they don't dedup
  -- on any available column combination), so the surrogate id PK + the loader's full DELETE+reload
  -- is the dedup. (The old uq_excl was dead — NULL excluding_agency_code — and a working version
  -- would have collapsed ~114k real rows; dropped in 2026-07-10_sam_exclusion_drop_dead_key.sql.)
  PRIMARY KEY (id),
  KEY idx_excl_uei (uei_sam),
  KEY idx_excl_status (record_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assistance (grant) subawards — SAM Assistance Subaward Reporting (FSRS).
CREATE TABLE sam_assistance_subaward (
  id                        BIGINT      NOT NULL AUTO_INCREMENT,
  subaward_report_id        VARCHAR(40) NULL,
  subaward_report_number    VARCHAR(40) NULL,
  subaward_number           VARCHAR(60) NULL,
  status                    VARCHAR(20) NULL,     -- Published | Deleted
  submitted_date            DATE        NULL,
  report_updated_date       DATE        NULL,
  prime_entity_uei          CHAR(12)    NULL,     -- soft link entity(uei)
  prime_entity_name         VARCHAR(255) NULL,
  prime_award_key           VARCHAR(64) NULL,
  fain                      VARCHAR(40) NULL,
  aln                       VARCHAR(24) NULL,     -- assistanceListingNumber[0].number
  aln_title                 VARCHAR(255) NULL,
  agency_code               VARCHAR(20) NULL,
  funding_agency_code       VARCHAR(20) NULL,
  funding_agency_name       VARCHAR(255) NULL,
  awarding_agency_code      VARCHAR(20) NULL,
  awarding_agency_name      VARCHAR(255) NULL,
  sub_vendor_uei            CHAR(12)    NULL,     -- soft link entity(uei)
  sub_vendor_name           VARCHAR(255) NULL,
  sub_parent_uei            CHAR(12)    NULL,
  sub_parent_name           VARCHAR(255) NULL,
  subaward_amount           DECIMAL(15,2) NULL,
  subaward_date             DATE        NULL,
  base_obligation_date      DATE        NULL,
  total_fed_funding_amount  DECIMAL(15,2) NULL,
  base_assistance_type_code VARCHAR(20) NULL,
  base_assistance_type_desc VARCHAR(100) NULL,
  subaward_description      MEDIUMTEXT  NULL,
  project_description       MEDIUMTEXT  NULL,
  pop_city                  VARCHAR(100) NULL,
  pop_state                 VARCHAR(60) NULL,
  pop_zip                   VARCHAR(12) NULL,
  pop_congressional_district VARCHAR(10) NULL,
  -- SAM-exclusive detail for the for-profit & foreign recipient analysis
  sub_business_types        JSON        NULL,     -- subBusinessType [{code,name},...]
  sub_vendor_country        VARCHAR(3)  NULL,     -- vendorPhysicalAddress country code
  sub_top_pay_employees     JSON        NULL,     -- subTopPayEmployee (exec compensation)
  last_synced               DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asub_report (subaward_report_id),
  KEY idx_asub_prime (prime_entity_uei),
  KEY idx_asub_sub (sub_vendor_uei),
  KEY idx_asub_aln (aln),
  KEY idx_asub_fain (fain),
  KEY idx_asub_country (sub_vendor_country),
  -- the admin console takes MAX() of both columns (status poll / delta --since);
  -- unindexed these were ~7s full scans of the multi-GB table
  KEY idx_asub_synced (last_synced),
  KEY idx_asub_submitted (submitted_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acquisition (contract) subawards — SAM Acquisition Subaward Reporting (FSRS).
CREATE TABLE sam_acquisition_subaward (
  id                       BIGINT       NOT NULL AUTO_INCREMENT,
  subaward_report_id       VARCHAR(40)  NULL,
  subaward_report_number   VARCHAR(40)  NULL,
  subaward_number          VARCHAR(60)  NULL,
  submitted_date           DATE         NULL,
  prime_contract_key       VARCHAR(64)  NULL,
  piid                     VARCHAR(60)  NULL,
  agency_id                VARCHAR(20)  NULL,
  referenced_idv_piid      VARCHAR(60)  NULL,
  prime_entity_uei         CHAR(12)     NULL,     -- soft link entity(uei)
  prime_entity_name        VARCHAR(255) NULL,
  prime_award_type         VARCHAR(60)  NULL,
  total_contract_value     DECIMAL(18,2) NULL,
  base_award_date_signed   DATE         NULL,
  sub_entity_uei           CHAR(12)     NULL,     -- soft link entity(uei)
  sub_entity_name          VARCHAR(255) NULL,     -- subEntityLegalBusinessName
  sub_entity_dba_name      VARCHAR(255) NULL,
  sub_parent_uei           CHAR(12)     NULL,
  subaward_amount          DECIMAL(15,2) NULL,
  subaward_date            DATE         NULL,
  prime_naics_code         VARCHAR(10)  NULL,
  prime_naics_description  VARCHAR(255) NULL,
  funding_agency_code      VARCHAR(20)  NULL,
  funding_agency_name      VARCHAR(255) NULL,
  contracting_agency_code  VARCHAR(20)  NULL,
  contracting_agency_name  VARCHAR(255) NULL,
  description_of_requirement MEDIUMTEXT NULL,
  subaward_description     MEDIUMTEXT   NULL,
  pop_city                 VARCHAR(100) NULL,
  pop_state                VARCHAR(60)  NULL,
  pop_zip                  VARCHAR(12)  NULL,
  pop_congressional_district VARCHAR(10) NULL,
  last_synced              DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_csub_report (subaward_report_id),
  KEY idx_csub_prime (prime_entity_uei),
  KEY idx_csub_sub (sub_entity_uei),
  KEY idx_csub_piid (piid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP E — Reference dimensions (program & agency lookups)
-- =============================================================================

-- Assistance Listings catalog (SAM Assistance Listings API, ~2.8k active).
-- Resolves an ALN (e.g. 93.600) to program title + owning agency.
-- fac_federal_awards.aln joins here (soft link).
CREATE TABLE assistance_listing (
  assistance_listing_id VARCHAR(24) NOT NULL,     -- ALN / CFDA number, e.g. 93.600
  program_id            VARCHAR(40) NULL,         -- SAM internal id (per-version hash)
  title                 VARCHAR(500) NULL,
  status                VARCHAR(20) NULL,          -- Active | Inactive
  version               VARCHAR(10) NULL,
  published_date        DATETIME    NULL,
  popular_long_name     VARCHAR(500) NULL,
  popular_short_name    VARCHAR(255) NULL,
  department            VARCHAR(255) NULL,
  department_code       VARCHAR(10) NULL,
  agency                VARCHAR(255) NULL,
  agency_code           VARCHAR(10) NULL,
  program_web_page      VARCHAR(500) NULL,
  objective             MEDIUMTEXT  NULL,
  assistance_listing_description MEDIUMTEXT NULL,
  is_funded_current_fy  TINYINT(1)  NULL,
  last_synced           DATETIME    NULL,
  PRIMARY KEY (assistance_listing_id),
  KEY idx_al_agency (agency_code),
  KEY idx_al_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Federal Hierarchy (SAM Federal Hierarchy API). Department/agency/office tree.
-- NOTE: agency_code here is the CGAC code (e.g. 7022), which differs from FAC's
-- 2-digit ALN prefix. ALN-prefix -> agency is best resolved via assistance_listing.
CREATE TABLE federal_agency (
  fhorgid           BIGINT      NOT NULL,          -- federal hierarchy org id
  fhorgname         VARCHAR(255) NULL,
  fhorgtype         VARCHAR(40) NULL,              -- DEPARTMENT | AGENCY | OFFICE | ...
  status            VARCHAR(20) NULL,
  parent_orgid      BIGINT      NULL,              -- fhdeptindagencyorgid (self-ref, soft)
  agency_org_name   VARCHAR(255) NULL,             -- fhagencyorgname
  agency_code       VARCHAR(20) NULL,              -- CGAC agencycode
  created_date      DATETIME    NULL,
  last_updated_date DATETIME    NULL,
  last_synced       DATETIME    NULL,
  PRIMARY KEY (fhorgid),
  KEY idx_fa_code (agency_code),
  KEY idx_fa_type (fhorgtype),
  KEY idx_fa_parent (parent_orgid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP D — Operational
-- =============================================================================

-- State/territory -> state-government UEI(s) crosswalk. Editable admin table that
-- identifies which recipients ARE a state government, powering the "State Govt"
-- entity-type facet on the recipient search. Seeded by name match; hand-correctable.
CREATE TABLE state_uei (
  state_code  CHAR(2)      NOT NULL,
  label       VARCHAR(100) NULL,
  ueis        TEXT         NULL,     -- one UEI per line (also accepts comma-separated)
  note        VARCHAR(255) NULL,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Curated component-UEI links: agencies that belong to a parent government's reporting
-- entity but were never declared as additional UEIs on its SF-SAC (first case: Oklahoma —
-- its FY2023 statewide SEFA names ~45 expending agencies; the SF-SAC said "No"). Feeds the
-- MONEY surfaces only (usa_awards rollup, award/txn crawls, Entity Info Related UEIs);
-- the evaluation reads state_uei (succession), and fac_additional_ueis stays a pure mirror
-- of what the auditee filed — its emptiness is itself evidence.
CREATE TABLE entity_related_uei (
  uei         CHAR(12)     NOT NULL,                       -- parent entity (profile UEI)
  related_uei CHAR(12)     NOT NULL,                       -- component agency UEI
  source      VARCHAR(20)  NOT NULL DEFAULT 'curated',     -- provenance of the link
  note        VARCHAR(255) NULL,
  added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (uei, related_uei),
  KEY idx_eru_related (related_uei)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration ledger (apply_migrations.php locally; deploy.ps1 on prod). schema.sql
-- already incorporates every migration listed below, so a fresh install seeds them
-- as applied — the runner only ever applies files NOT in this list.
--
-- IMPORTANT: every file in migrations/ MUST be listed here. The runner applies any
-- unlisted file, and several migrations are non-idempotent against this schema (e.g.
-- DROP INDEX idx_awards_uei — already absent here; ADD COLUMN entity.has_addl — already
-- present here), so a missing entry makes apply_migrations.php fatally error on a fresh
-- DB. Build-artifact migrations are seeded too, even though their tables are NOT defined
-- in this file: subaward_edge / subaward_entity_type (build_subaward_edge.php), and
-- entity_map_point / zip_centroid (build_entity_map_point.php / seed_zip_centroid.php)
-- are derived/reference artifacts DROP+CREATEd by their build scripts and shipped to prod
-- via deploy.ps1 -PushTable, so schema.sql intentionally does not redefine them. Every
-- other migration's end state IS folded into the DDL above (e.g. the 2026-07-01 quota /
-- progress_at columns + api_quota_obs, the 2026-07-10 outlay-month FK drop), so seeding
-- them here just tells the runner "already applied — skip". Keep this list == migrations/*
-- (enforced by api/tests/migrations_seed_test.php).
CREATE TABLE schema_migrations (
  filename   VARCHAR(255) NOT NULL,
  applied_at DATETIME     NOT NULL,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (filename, applied_at) VALUES
  ('2026-06-08_awards_uei_agency_index.sql', UTC_TIMESTAMP()),
  ('2026-06-10_fac_general_is_active.sql',   UTC_TIMESTAMP()),
  ('2026-06-10_usa_recipient_sync_meta.sql', UTC_TIMESTAMP()),
  ('2026-06-10_subaward_forprofit_fields.sql', UTC_TIMESTAMP()),
  ('2026-06-10_create_dump_skipped_fac_tables.sql', UTC_TIMESTAMP()),
  ('2026-06-11_perf_indexes.sql', UTC_TIMESTAMP()),
  ('2026-06-11_repair_finding_award_padding.sql', UTC_TIMESTAMP()),
  ('2026-06-12_drop_redundant_awards_uei_index.sql', UTC_TIMESTAMP()),
  ('2026-06-12_subaward_edge.sql', UTC_TIMESTAMP()),
  ('2026-06-12_subaward_edge_year.sql', UTC_TIMESTAMP()),
  ('2026-06-16_entity_has_addl.sql', UTC_TIMESTAMP()),
  ('2026-06-17_drop_unused_indexes.sql', UTC_TIMESTAMP()),
  ('2026-06-17_entity_directory_columns.sql', UTC_TIMESTAMP()),
  ('2026-06-17_entity_addl_backfill.sql', UTC_TIMESTAMP()),
  ('2026-06-17_usa_award_txn_month.sql', UTC_TIMESTAMP()),
  ('2026-06-22_entity_map_point.sql', UTC_TIMESTAMP()),
  ('2026-06-22_zip_centroid.sql', UTC_TIMESTAMP()),
  ('2026-07-01_synclog_progress_at.sql', UTC_TIMESTAMP()),
  ('2026-07-01_quota_tracking.sql', UTC_TIMESTAMP()),
  ('2026-07-01_usa_award_outlay_month.sql', UTC_TIMESTAMP()),
  ('2026-07-08_entity_related_uei.sql', UTC_TIMESTAMP()),
  ('2026-07-10_outlay_month_drop_fk.sql', UTC_TIMESTAMP()),
  ('2026-07-10_txn_month_drop_fk.sql', UTC_TIMESTAMP()),
  ('2026-07-10_fac_additional_ueis_indexes.sql', UTC_TIMESTAMP()),
  ('2026-07-10_sam_exclusion_drop_dead_key.sql', UTC_TIMESTAMP()),
  ('2026-07-10_drop_dead_indexes.sql', UTC_TIMESTAMP()),
  ('2026-07-16_fac_general_gaap_cat.sql', UTC_TIMESTAMP()),
  ('2026-07-16_fac_federal_awards_opinion_index.sql', UTC_TIMESTAMP()),
  ('2026-07-16_fac_finding_extract_join_index.sql', UTC_TIMESTAMP());

CREATE TABLE sync_log (
  id            BIGINT      NOT NULL AUTO_INCREMENT,
  source        VARCHAR(12) NOT NULL,        -- fac | usaspending | sam
  scope         VARCHAR(40) NULL,            -- audit_year or uei
  table_name    VARCHAR(50) NULL,
  rows_upserted INT         NULL,
  requests      INT         NULL,            -- API requests this run (exact where the API reports it; see api_quota_obs)
  status        VARCHAR(20) NULL,            -- ok | error
  message       TEXT        NULL,
  started_at    DATETIME    NULL,
  progress_at   DATETIME    NULL,            -- last progress tick; a RUNNING row stale on this is a cut-off run
  finished_at   DATETIME    NULL,
  PRIMARY KEY (id),
  KEY idx_synclog_source (source),
  KEY idx_synclog_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observed API rate-limit state for the Data Status console — ground truth where an API
-- reports it (FAC returns X-RateLimit-* on every response; SAM only a reset time on a 429),
-- captured by FacClient / the SAM syncs. One row per source (latest observation wins).
CREATE TABLE api_quota_obs (
  source       VARCHAR(32)  NOT NULL,          -- 'fac', 'sam', ...
  limit_total  INT          NULL,              -- window cap, from the API when it reports one
  remaining    INT          NULL,              -- requests left in the window (header APIs only)
  observed_at  DATETIME     NULL,              -- UTC, when remaining/limit was read from a response
  reset_at     DATETIME     NULL,              -- UTC, when the window resets (SAM: next midnight on 429)
  note         VARCHAR(255) NULL,              -- free-text (e.g. 'daily limit reached')
  PRIMARY KEY (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- GROUP F — AERO Risk Score (derived; one row per recipient UEI)
-- Computed by api/sync/compute_scores.php from the FAC data above. The seven
-- weighted components and their rules live in api/lib/Score.php. Sub-scores are
-- 0-100 (higher = more risk); composite is their weighted average; tier comes
-- from the 0-19/20-39/40-59/60-79/80-100 bands.
-- =============================================================================
CREATE TABLE aero_score (
  uei                     CHAR(12)     NOT NULL,
  latest_audit_year       SMALLINT     NULL,
  -- denormalized from the latest audit for fast recipient search/listing
  recipient_name          VARCHAR(255) NULL,
  entity_type             VARCHAR(50)  NULL,
  state                   CHAR(2)      NULL,
  audit_count             SMALLINT     NULL,
  federal_latest          BIGINT       NULL,   -- total_amount_expended, latest audit
  cognizant_agency        VARCHAR(2)   NULL,
  is_hhs                  TINYINT(1)   NOT NULL DEFAULT 0,  -- has any ALN-93 (HHS) award
  trend                   JSON         NULL,   -- [{year, composite}] per audit year
  -- component sub-scores (0-100). NULL = not applicable / data unavailable
  sc_internal_control     DECIMAL(5,2) NULL,   -- 25%  2 CFR 200.303
  sc_repeat_findings      DECIMAL(5,2) NULL,   -- 20%  2 CFR 200.511
  sc_questioned_costs     DECIMAL(5,2) NULL,   -- 15%  2 CFR 200 Subpart F
  sc_reporting_timeliness DECIMAL(5,2) NULL,   -- 15%  2 CFR 200.512
  sc_cash_financial       DECIMAL(5,2) NULL,   -- 10%  2 CFR 200.302/.305
  sc_subrecipient         DECIMAL(5,2) NULL,   -- 10%  2 CFR 200.331-.333
  sc_cap_quality          DECIMAL(5,2) NULL,   --  5%  2 CFR 200.511(c)
  composite_score         DECIMAL(5,2) NOT NULL,
  tier                    VARCHAR(12)  NOT NULL, -- Clean|Minimal|Moderate|Elevated|Substantial|Severe
  drivers                 JSON         NULL,     -- raw inputs behind each sub-score (explainability)
  computed_at             DATETIME     NOT NULL,
  PRIMARY KEY (uei),
  KEY idx_score_composite (composite_score),
  KEY idx_score_tier (tier),
  KEY idx_score_year (latest_audit_year),
  KEY idx_score_name (recipient_name),
  KEY idx_score_state (state),
  KEY idx_score_hhs (is_hhs),
  CONSTRAINT fk_score_entity FOREIGN KEY (uei)
      REFERENCES entity (uei) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
