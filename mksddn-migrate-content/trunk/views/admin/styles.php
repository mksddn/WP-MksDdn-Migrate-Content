<?php
/**
 * Admin page styles template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
.mksddn-mc-section{margin-top:2rem;padding:1.5rem;border:1px solid #e5e7eb;border-radius:12px;background:#fff;}
.mksddn-mc-section:first-of-type{margin-top:1rem;}
.mksddn-mc-section h2{margin-top:0;margin-bottom:.25rem;}
.mksddn-mc-section p{margin-top:0;color:#4b5563;}
.mksddn-mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;margin-top:1.5rem;}
.mksddn-mc-card{background:#fdfdfd;border:1px solid #e1e1e1;border-radius:10px;padding:1.25rem;box-shadow:0 1px 2px rgba(15,23,42,0.05);}
.mksddn-mc-card h3{margin-top:0;}
.mksddn-mc-card--muted{background:#fafafa;opacity:.75;}
.mksddn-mc-field,
.mksddn-mc-basic-selection{margin-bottom:1.25rem;}
.mksddn-mc-field h4,
.mksddn-mc-basic-selection h4{margin:0 0 .35rem;font-size:14px;color:#111827;}
.mksddn-mc-field label,
.mksddn-mc-basic-selection label{display:block;font-weight:500;margin-bottom:.25rem;}
.mksddn-mc-basic-selection select,
.mksddn-mc-field select,
.mksddn-mc-field input[type="file"],
.mksddn-mc-format-selector select{width:100%;}
.mksddn-mc-selection-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
.mksddn-mc-selection-grid select{min-height:140px;}
.mksddn-mc-history table{width:100%;border-collapse:collapse;margin-top:1rem;}
.mksddn-mc-history table th,
.mksddn-mc-history table td{padding:.5rem .75rem;border-bottom:1px solid #e5e7eb;text-align:left;font-size:13px;}
.mksddn-mc-history table th{background:#f9fafb;font-weight:600;color:#111827;}
.mksddn-mc-badge{display:inline-flex;align-items:center;padding:0.1rem 0.55rem;border-radius:999px;font-size:12px;line-height:1.4;}
.mksddn-mc-badge--success{background:#e6f4ea;color:#1f7a3f;}
.mksddn-mc-badge--error{background:#fdecea;color:#b42318;}
.mksddn-mc-badge--running{background:#e0ecff;color:#1d4ed8;}
.mksddn-mc-history__actions form{display:inline-block;margin-right:.5rem;}
.mksddn-mc-history__actions button{margin-top:0;}
.mksddn-mc-user-table-wrapper{max-height:320px;overflow:auto;margin-top:1rem;}
.mksddn-mc-user-table td select{width:100%;}
.mksddn-mc-user-actions{margin-top:1rem;}
.mksddn-mc-inline-form{margin-top:0.75rem;}
.mksddn-mc-import-source-toggle{display:flex;gap:1rem;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e5e7eb;}
.mksddn-mc-import-source-toggle label{display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;}
.mksddn-mc-import-source-toggle input[type="radio"]{margin:0;}
.mksddn-mc-import-source-server select{margin-bottom:0.5rem;}
</style>

