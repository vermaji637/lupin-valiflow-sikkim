INSERT INTO laravel_db.dbo.form_templates(form_key, schema_json, created_at, updated_at)
VALUES (
    'ahu_requalification_v2',
    '{
  "meta": {
    "template_key": "ahu_periodic_requalification_v1",
    "title": "PERIODIC REQUALIFICATION PROTOCOL AND REPORT",
    "page_text": "1 of 25",
    "logo": {
      "type": "image",
      "src": "http://localhost:81/proposal_pdf/public/admin/img/lupin1.png",
      "alt": "Company logo",
      "style": { "height": "80px", "width": "auto" }
    }
  },

  "datasources": {
    "documents": {
      "type": "laravel_collection",
      "var": "documents",
      "value": "id",
      "label": "title"
    },
    "users": {
      "type": "laravel_collection",
      "var": "users",
      "value": "id",
      "label": "first_name"
    },
    "auth_user_fullname": {
      "type": "laravel_expression",
      "expr": "Auth::user()->fullname"
    }
  },

  "layout": {
    "container_id": "content",
    "print_note": "Note: Replace sample values with actual data. Use the toolbar to auto-fill todays date or to clear all fields. Printing hides the toolbar and form outlines."
  },

  "form": {
    "action": {
      "type": "laravel_route",
      "name": "createoffer"
    },
    "method": "POST",
    "csrf": true,

    "blocks": [
      {
        "type": "table",
        "key": "cover_header",
        "class": "",
        "rows": [
          {
            "cells": [
              {
                "rowspan": 4,
                "style": "width:15%; text-align:center;",
                "content": [
                  { "type": "image_ref", "ref": "meta.logo" }
                ]
              },
              {
                "colspan": 3,
                "class": "center header-title",
                "content": [
                  {
                    "type": "field",
                    "name": "company_name",
                    "field_type": "text",
                    "class": "input input-lg center",
                    "default": "Company Name, (Location)"
                  }
                ]
              }
            ]
          },
          {
            "cells": [
              {
                "colspan": 3,
                "class": "center sub-title",
                "content": [
                  { "type": "text", "text": "PERIODIC REQUALIFICATION PROTOCOL AND REPORT" }
                ]
              }
            ]
          },
          {
            "cells": [
              {
                "style": "width:25%;",
                "content": [
                  { "type": "label", "text": "FACILITY/SECTION" },
                  {
                    "type": "field",
                    "name": "facility_section",
                    "field_type": "text",
                    "id": "facility",
                    "class": "input",
                    "default": ""
                  }
                ]
              },
              {
                "style": "width:30%;",
                "content": [
                  { "type": "label", "text": "EQUIPMENT NAME" },
                  {
                    "type": "field",
                    "name": "equipment_name",
                    "field_type": "text",
                    "id": "equipment_name",
                    "class": "input",
                    "default": "Air Handling Unit"
                  }
                ]
              },
              {
                "style": "width:30%;",
                "content": [
                  { "type": "label", "text": "EQUIPMENT ID. NO." },
                  {
                    "type": "field",
                    "name": "equipment_id",
                    "field_type": "text",
                    "id": "equipment_id",
                    "class": "input",
                    "default": "/ENG/AHU-005"
                  }
                ]
              }
            ]
          },
          {
            "cells": [
              {
                "content": [
                  { "type": "label", "text": "DOCUMENT NO." },
                  {
                    "type": "field",
                    "name": "document_id",
                    "field_type": "select",
                    "id": "document_no",
                    "class": "input",
                    "options": {
                      "source": "documents",
                      "placeholder": "-- Select Document --"
                    }
                  },
                  {
                    "type": "field",
                    "name": "document_no",
                    "field_type": "text",
                    "class": "input",
                    "default": ""
                  }
                ]
              },
              {
                "content": [
                  { "type": "label", "text": "VERSION" },
                  {
                    "type": "field",
                    "name": "version",
                    "field_type": "text",
                    "class": "input",
                    "default": "00"
                  }
                ]
              },
              {
                "content": [
                  { "type": "label", "text": "PAGE NO." },
                  { "type": "text", "text": "1 of 25" }
                ]
              }
            ]
          }
        ]
      },

      { "type": "heading", "level": 3, "text": "PRE-APPROVAL SIGNATURE" },

      {
        "type": "table",
        "key": "pre_approval_prepared_by",
        "rows": [
          {
            "type": "header",
            "class": "section-title",
            "cells": [
              { "type": "th", "style": "width:30%;", "text": "PREPARED BY" },
              { "type": "th", "style": "width:40%;", "text": "NAME & DESIGNATION" },
              { "type": "th", "style": "width:30%;", "text": "SIGNATURE & DATE" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "USER DEPARTMENT (ENGINEERING)" },
              {
                "type": "field",
                "name": "prepared_by_name",
                "field_type": "text",
                "class": "input",
                "default_from": { "source": "auth_user_fullname" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "prepared_by_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "prepared_by_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          }
        ]
      },

      {
        "type": "table",
        "key": "pre_approval_checked_by",
        "rows": [
          {
            "type": "header",
            "class": "section-title",
            "cells": [
              { "type": "th", "style": "width:30%;", "text": "CHECKED BY" },
              { "type": "th", "style": "width:40%;", "text": "NAME & DESIGNATION" },
              { "type": "th", "style": "width:30%;", "text": "SIGNATURE & DATE" }
            ]
          },

          {
            "cells": [
              { "type": "text", "text": "ENGINEERING" },
              {
                "type": "field",
                "name": "checked_by_engineering_name",
                "field_type": "select",
                "class": "form-control form-control",
                "options": { "source": "users", "placeholder": "Select user" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "checked_by_engineering_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "checked_by_engineering_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          },

          {
            "cells": [
              { "type": "text", "text": "USER DEPARTMENT (PRODUCTION)" },
              {
                "type": "field",
                "name": "checked_by_production_name",
                "field_type": "select",
                "class": "form-control form-control",
                "options": { "source": "users", "placeholder": "Select user" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "checked_by_production_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "checked_by_production_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          },

          {
            "cells": [
              { "type": "text", "text": "VALIDATION" },
              {
                "type": "field",
                "name": "checked_by_validation_name",
                "field_type": "select",
                "class": "form-control form-control",
                "options": { "source": "users", "placeholder": "Select user" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "checked_by_validation_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "checked_by_validation_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          }
        ]
      },

      {
        "type": "table",
        "key": "pre_approval_approved_by",
        "rows": [
          {
            "type": "header",
            "class": "section-title",
            "cells": [
              { "type": "th", "style": "width:30%;", "text": "APPROVED BY" },
              { "type": "th", "style": "width:40%;", "text": "NAME & DESIGNATION" },
              { "type": "th", "style": "width:30%;", "text": "SIGNATURE & DATE" }
            ]
          },

          {
            "cells": [
              { "type": "text", "text": "USER / VALIDATION" },
              {
                "type": "field",
                "name": "approved_by_user_name",
                "field_type": "select",
                "class": "form-control form-control",
                "options": { "source": "users", "placeholder": "Select user" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "approved_by_user_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "approved_by_user_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          },

          {
            "cells": [
              { "type": "text", "text": "QUALITY ASSURANCE" },
              {
                "type": "field",
                "name": "approved_by_quality_name",
                "field_type": "select",
                "class": "form-control form-control",
                "options": { "source": "users", "placeholder": "Select user" }
              },
              {
                "type": "group",
                "content": [
                  {
                    "type": "field",
                    "name": "approved_by_quality_signature",
                    "field_type": "text",
                    "class": "input",
                    "placeholder": "Signature"
                  },
                  {
                    "type": "field",
                    "name": "approved_by_quality_date",
                    "field_type": "date",
                    "class": "input-date"
                  }
                ]
              }
            ]
          }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "EFFECTIVE DATE:" },
          { "type": "field", "name": "effective_date", "field_type": "date", "class": "input-date" }
        ]
      },

      { "type": "heading", "level": 3, "text": "TABLE OF CONTENTS" },
      {
        "type": "table",
        "key": "toc",
        "class": "noborder",
        "rows": [
          { "cells": [{ "type": "text", "text": "1.0 OBJECTIVE" }, { "type": "text", "text": "" }, { "type": "text", "text": "3" }] },
          { "cells": [{ "type": "text", "text": "2.0 SCOPE" }, { "type": "text", "text": "" }, { "type": "text", "text": "3" }] },
          { "cells": [{ "type": "text", "text": "3.0 RESPONSIBILITY" }, { "type": "text", "text": "" }, { "type": "text", "text": "4" }] },
          { "cells": [{ "type": "text", "text": "4.0 TYPE OF QUALIFICATION" }, { "type": "text", "text": "" }, { "type": "text", "text": "4" }] },
          { "cells": [{ "type": "text", "text": "5.0 EQUIPMENT / SYSTEM DETAILS" }, { "type": "text", "text": "" }, { "type": "text", "text": "5" }] },
          { "cells": [{ "type": "text", "text": "6.0 PRE-REQUISITES" }, { "type": "text", "text": "" }, { "type": "text", "text": "7" }] },
          { "cells": [{ "type": "text", "text": "7.0 APPLICABLE SOPs" }, { "type": "text", "text": "" }, { "type": "text", "text": "8" }] },
          { "cells": [{ "type": "text", "text": "8.0 PRE-QUALIFICATION CHECKS" }, { "type": "text", "text": "" }, { "type": "text", "text": "8" }] },
          { "cells": [{ "type": "text", "text": "9.0 REQUALIFICATION CHECKS" }, { "type": "text", "text": "" }, { "type": "text", "text": "12" }] },
          { "cells": [{ "type": "text", "text": "10.0 DEVIATIONS (IF ANY)" }, { "type": "text", "text": "" }, { "type": "text", "text": "21" }] },
          { "cells": [{ "type": "text", "text": "11.0 SUMMARY & CONCLUSION" }, { "type": "text", "text": "" }, { "type": "text", "text": "21" }] },
          { "cells": [{ "type": "text", "text": "12.0 REFERENCES" }, { "type": "text", "text": "" }, { "type": "text", "text": "22" }] },
          { "cells": [{ "type": "text", "text": "13.0 ABBREVIATIONS" }, { "type": "text", "text": "" }, { "type": "text", "text": "22" }] },
          { "cells": [{ "type": "text", "text": "14.0 REVISION HISTORY" }, { "type": "text", "text": "" }, { "type": "text", "text": "23" }] }
        ]
      },

      { "type": "heading", "level": 5, "text": "1.0 OBJECTIVE" },
      {
        "type": "paragraph",
        "text": "The protocol provides guidelines for the requalification of Air Handling Unit ID. No. /ENG/AHU-005 in the facility of Company Name Ltd. Location to establish high degree of assurance that the equipment is maintained at a qualified stage since its initial installation and is fit for its intended use when operated as per applicable SOP."
      },

      { "type": "heading", "level": 5, "text": "2.0 SCOPE" },
      {
        "type": "paragraph",
        "text": "This protocol is applicable for Air Handling Unit as details given in below table. This protocol provides directions / procedure for requalification to provide documented evidence that Air Handling Unit is operated in accordance with the requirements of the product, process, and safety and cGMP norms. The subjected equipment is identified as Air Handling Unit ID. No. /ENG/AHU-005 located at Service Floor."
      },

      {
        "type": "repeat_table",
        "key": "detailsTable",
        "table_id": "detailsTable",
        "add_rows_control": { "type": "number", "id": "rowCountDetails", "default": 1, "min": 1 },
        "columns": ["Details", "Description"],
        "row_schema": {
          "detail": { "type": "text", "default": "Tag. No." },
          "tag_no": { "type": "text", "class": "input documentID", "default": "/ENG/AHU-005" }
        },
        "min_rows": 1
      },

      { "type": "heading", "level": 5, "text": "3.0 RESPONSIBILITY" },
      {
        "type": "list",
        "style": "ul",
        "items": [
          "Engineering department is responsible to prepare & review the re-qualification protocol in consultation with Validation / QA, execution of the requalification and preparation of re-qualification summary report.",
          "User and engineering department shall execute the re-qualification activity.",
          "Engineering department is responsible to review the protocol for adequacy and accuracy, and to provide the equipment maintenance history.",
          "Validation department is responsible to review the requalification protocol and summary report.",
          "Quality Assurance is responsible to provide guidance, wherever required to the related departments, review & approval of the protocol & requalification report."
        ]
      },

      { "type": "heading", "level": 5, "text": "4.0 TYPE OF QUALIFICATION" },
      {
        "type": "paragraph",
        "content": [
          {
            "type": "text",
            "text": "As the qualification of Air Handling Unit having I.D. /ENG/AHU-005 has been completed on "
          },
          { "type": "field", "name": "qualification_date", "field_type": "date", "class": "input-date", "default": "2017-01-13" },
          { "type": "text", "text": " as per document no. " },
          { "type": "field", "name": "qualification_document_no", "field_type": "text", "class": "input", "default": "/PQR/AHU/16-001" },
          { "type": "text", "text": ". Hence periodic re-qualification is to be performed as per frequency defined in SOP." }
        ]
      },

      { "type": "heading", "level": 3, "text": "Qualification History" },
      {
        "type": "table",
        "key": "qualification_history",
        "rows": [
          {
            "type": "header",
            "cells": [
              { "type": "th", "text": "Qualification" },
              { "type": "th", "text": "Details of qualification (Report no and approval date)" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "Initial Qualification (PQ)" },
              {
                "type": "group",
                "content": [
                  { "type": "label", "text": "Report No." },
                  { "type": "field", "name": "initial_qualification_report_no", "field_type": "text", "class": "input", "default": "/PQR/AHU/16-001" },
                  { "type": "label", "text": "Approval Date" },
                  { "type": "field", "name": "initial_qualification_approval_date", "field_type": "date", "class": "input-date", "default": "2017-01-13" }
                ]
              }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "Other qualifications after initial" },
              { "type": "field", "name": "other_qualifications", "field_type": "text", "class": "input", "default": "NA" }
            ]
          }
        ]
      },

      { "type": "heading", "level": 5, "text": "5.0 EQUIPMENT / SYSTEM DETAILS" },
      { "type": "heading", "level": 6, "text": "Details of Air Handling Unit" },

      {
        "type": "repeat_table",
        "key": "accessoriesTable",
        "table_id": "accessoriesTable",
        "add_rows_control": { "type": "number", "id": "rowCountAccessories", "default": 1, "min": 1 },
        "columns": ["DETAILS", "DESCRIPTION"],
        "row_schema": {
          "detail": { "type": "text", "default": "Name of Equipment" },
          "equipment_name_detail": { "type": "text", "class": "input", "default": "Air Handling Unit" }
        },
        "min_rows": 1
      },

      {
        "type": "repeat_table",
        "key": "accessories",
        "table_id": "accessories",
        "add_rows_control": { "type": "number", "id": "rowCountDetails1", "default": 1, "min": 1 },
        "columns": ["Connected Accessories", "Description"],
        "row_schema": {
          "accessory": { "type": "text", "default": "Blower / motor" },
          "description": { "type": "text", "class": "input", "default": "Plug type Blower ... static pressure of 135 MM of WC." }
        },
        "min_rows": 1
      },

      { "type": "heading", "level": 5, "text": "6.0 PRE-REQUISITES" },
      {
        "type": "paragraph",
        "text": "Protocol shall be approved with identification of all tests to be carried out ... reviewed and approved by all departments."
      },

      { "type": "heading", "level": 5, "text": "7.0 APPLICABLE STANDARD OPERATING PROCEDURE (SOPs)" },
      {
        "type": "table",
        "key": "sops",
        "rows": [
          {
            "type": "header",
            "cells": [
              { "type": "th", "text": "Sr. No." },
              { "type": "th", "text": "Title of the SOP" },
              { "type": "th", "text": "SOP No." },
              { "type": "th", "text": "Effective Date" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "1" },
              { "type": "field", "name": "sop_title_1", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_no_1", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_date_1", "field_type": "date", "class": "input-date" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "2" },
              { "type": "field", "name": "sop_title_2", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_no_2", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_date_2", "field_type": "date", "class": "input-date" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "3" },
              { "type": "field", "name": "sop_title_3", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_no_3", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_date_3", "field_type": "date", "class": "input-date" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "4" },
              { "type": "field", "name": "sop_title_4", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_no_4", "field_type": "text", "class": "input" },
              { "type": "field", "name": "sop_date_4", "field_type": "date", "class": "input-date" }
            ]
          }
        ]
      },

      { "type": "heading", "level": 5, "text": "8.0 PRE-QUALIFICATION CHECKS" },
      { "type": "heading", "level": 3, "text": "Calibrations of Instruments" },
      {
        "type": "paragraph",
        "text": "Verify the calibration status of all the instruments and record the details below. Attach copies of certificates."
      },

      {
        "type": "repeat_table",
        "key": "calibration_instruments",
        "columns": [
          "Sr. No.",
          "Instrument Name",
          "Instrument ID",
          "Date of Calibration",
          "Due Date",
          "Checked By (Sign.)"
        ],
        "row_schema": {
          "sr_no": { "type": "text", "default": "" },
          "instrument_name": { "type": "text", "default": "" },
          "instrument_id": { "type": "text", "default": "" },
          "cal_date": { "type": "date", "default": "" },
          "due_date": { "type": "date", "default": "" },
          "checked_by": { "type": "text", "default": "" }
        },
        "default_rows": [
          { "sr_no": "1", "instrument_name": "Differential Pressure Gauge (across pre filter)" },
          { "sr_no": "2", "instrument_name": "Differential Pressure Gauge (across fine filter)" },
          { "sr_no": "3", "instrument_name": "Pressure gauge (chilled water inlet line)" },
          { "sr_no": "4", "instrument_name": "Pressure gauge (chilled water outlet line)" },
          { "sr_no": "5", "instrument_name": "Pressure gauge (hot water inlet line)" },
          { "sr_no": "6", "instrument_name": "Pressure gauge (hot water outlet line)" },
          { "sr_no": "7", "instrument_name": "Temperature gauge (chilled water inlet line)" },
          { "sr_no": "8", "instrument_name": "Temperature gauge (chilled water outlet line)" },
          { "sr_no": "9", "instrument_name": "Temperature gauge (hot water inlet line)" },
          { "sr_no": "10", "instrument_name": "Temperature gauge (hot water outlet line)" },
          { "sr_no": "11", "instrument_name": "Temperature / RH transmitter" },
          { "sr_no": "12", "instrument_name": "Differential pressure Transmitter" }
        ]
      },

      { "type": "heading", "level": 3, "text": "Dust Extractor" },
      {
        "type": "repeat_table",
        "key": "dust_extractor",
        "columns": ["Sr. No.", "Instrument Name", "Instrument ID", "Date of Calibration", "Due Date", "Checked By"],
        "row_schema": {
          "sr_no": { "type": "text", "default": "" },
          "instrument_name": { "type": "text", "default": "" },
          "instrument_id": { "type": "text", "default": "" },
          "cal_date": { "type": "date", "default": "" },
          "due_date": { "type": "date", "default": "" },
          "checked_by": { "type": "text", "default": "" }
        },
        "default_rows": [
          { "sr_no": "1", "instrument_name": "Differential Pressure Gauge (across Cartridge Filter)" },
          { "sr_no": "2", "instrument_name": "Differential Pressure Gauge (across fine filter)" },
          { "sr_no": "3", "instrument_name": "Differential Pressure Gauge (across HEPA filter)" }
        ]
      },

      { "type": "heading", "level": 3, "text": "Preventive Maintenance Details" },
      {
        "type": "repeat_table",
        "key": "preventive_maintenance",
        "columns": ["Sr. No.", "Equipment Name / ID", "Done on", "Due on", "Checked by (Engineering) (Sign./Date)"],
        "row_schema": {
          "sr_no": { "type": "text", "default": "" },
          "pm_equipment": { "type": "text", "default": "" },
          "pm_done_on": { "type": "date", "default": "" },
          "pm_due_on": { "type": "date", "default": "" },
          "pm_checked_by": { "type": "text", "default": "" }
        },
        "default_rows": [{ "sr_no": "1" }, { "sr_no": "2" }]
      },

      { "type": "heading", "level": 3, "text": "Any other specific tests" },

      {
        "type": "list",
        "style": "ul",
        "items": [
          "Airflow volume measurement / ACPH",
          "HEPA filter Integrity test",
          "Non-Viable particulate count test.",
          "Area Recovery test",
          "Airflow Pattern test",
           "Dust Extractor CFM measurement"
        ]
      },
      

      { "type": "heading", "level": 3, "text": "Verification with the current P & ID" },
      {
        "type": "group",
        "key": "history_card_1",
        "content": [
          { "type": "label", "text": "History card from date:" },
          { "type": "field", "name": "history_from_date_1", "field_type": "date", "class": "input-date" },
          { "type": "text", "text": "to" },
          { "type": "field", "name": "history_to_date_1", "field_type": "date", "class": "input-date" },
          { "type": "label", "text": "Reviewed by (Engineering Department)" },
          { "type": "field", "name": "history_reviewed_by_1", "field_type": "text", "class": "input" },
          { "type": "label", "text": "Recommendation:" },
          { "type": "field", "name": "history_recommendation_1", "field_type": "text", "class": "input" }
        ]
      },
      {
        "type": "group",
        "key": "history_card_2",
        "content": [
          { "type": "label", "text": "History card from date:" },
          { "type": "field", "name": "history_from_date_2", "field_type": "date", "class": "input-date" },
          { "type": "text", "text": "to" },
          { "type": "field", "name": "history_to_date_2", "field_type": "date", "class": "input-date" },
          { "type": "label", "text": "Reviewed by (Engineering Department)" },
          { "type": "field", "name": "history_reviewed_by_2", "field_type": "text", "class": "input" },
          { "type": "label", "text": "Recommendation:" },
          { "type": "field", "name": "history_recommendation_2", "field_type": "text", "class": "input" }
        ]
      },

      { "type": "heading", "level": 3, "text": "History of Equipment / system" },
      { "type": "field", "name": "equipment_history", "field_type": "textarea", "rows": 4, "style": "width:100%; border:1px solid #ddd; padding:8px;" },

      { "type": "heading", "level": 3, "text": "Review of Change Controls related to the Equipment / system" },
      { "type": "paragraph", "text": "Review the Change Controls related to the Equipment / system under requalification and summarize;" },

      {
        "type": "table",
        "key": "change_controls",
        "rows": [
          {
            "type": "header",
            "cells": [
              { "type": "th", "text": "S. No." },
              { "type": "th", "text": "Ref. Change Control No." },
              { "type": "th", "text": "Summary of Changes" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "1" },
              { "type": "field", "name": "change_control_no_1", "field_type": "text", "class": "input" },
              { "type": "field", "name": "change_summary_1", "field_type": "text", "class": "input" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "2" },
              { "type": "field", "name": "change_control_no_2", "field_type": "text", "class": "input" },
              { "type": "field", "name": "change_summary_2", "field_type": "text", "class": "input" }
            ]
          }
        ]
      },

      { "type": "label", "text": "Remarks / Recommendation (if any):" },
      { "type": "field", "name": "change_remarks", "field_type": "textarea", "rows": 3, "style": "width:100%; border:1px solid #ddd; padding:8px;" },
      { "type": "paragraph", "content": [{ "type": "label", "text": "Reviewed By: (Sign/Date)" }, { "type": "field", "name": "change_reviewed_by", "field_type": "text", "class": "input" }] },

      { "type": "heading", "level": 5, "text": "9.0 REQUALIFICATION CHECKS" },
      { "type": "heading", "level": 3, "text": "Installation Re-qualification checks" },

      {
        "type": "repeat_table",
        "key": "installation_requalification_checks",
        "columns": [
          "Checks",
          "Acceptance criteria",
          "Check",
          "Observation",
          "Engineering Check",
          "Done by (Engineering)"
        ],
        "row_schema": {
          "check_label": { "type": "text", "default": "" },
          "criteria": { "type": "text", "default": "" },
          "check": { "type": "checkbox", "default": false },
          "observation": { "type": "text", "default": "" },
          "eng_check": { "type": "checkbox", "default": false },
          "done_by": { "type": "text", "default": "" }
        },
        "default_rows": [
          {
            "check_label": "Electrical connections",
            "criteria": "All the electrical connection in the panel should be connected properly"
          },
          {
            "check_label": "Check chilled water connections",
            "criteria": "Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: 2 to 8°C"
          },
          {
            "check_label": "Check hot water connections",
            "criteria": "Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: ≥ 35.0°C"
          },
          { "check_label": "Leveling/ Centering/ Alignment", "criteria": "Should be properly leveled / centered / aligned" },
          {
            "check_label": "Access and Clearance",
            "criteria": "Adequate space is available for accessing the equipment for operation, cleaning & maintenance"
          }
        ]
      },

      { "type": "heading", "level": 3, "text": "General Checks: Verification of major components of equipment" },
      {
        "type": "repeat_table",
        "key": "general_component_checks",
        "columns": [
          "Major components",
          "Checks",
          "Methodology",
          "Acceptance criteria",
          "Check",
          "Observation/Result",
          "Engineering Check",
          "Done by"
        ],
        "row_schema": {
          "component": { "type": "text", "default": "" },
          "checks": { "type": "text", "default": "" },
          "methodology": { "type": "text", "default": "" },
          "criteria": { "type": "text", "default": "" },
          "check": { "type": "checkbox", "default": false },
          "observation": { "type": "text", "default": "" },
          "eng_check": { "type": "checkbox", "default": false },
          "done_by": { "type": "text", "default": "" }
        },
        "default_rows": [
          {
            "component": "Machine frame and body part",
            "checks": "Check any physical damage to the machine frame & body part.",
            "methodology": "Visual Inspection",
            "criteria": "No physical damage."
          },
          { "component": "Fresh air filter", "checks": "", "methodology": "Visual Inspection", "criteria": "Filter should not be damaged or choked" },
          { "component": "Pre filter", "checks": "", "methodology": "Visual Inspection", "criteria": "Filter should not be damaged or choked" },
          { "component": "Fine filter", "checks": "", "methodology": "Visual Inspection", "criteria": "Filter should not be damaged or choked" },
          { "component": "Bleed air filter", "checks": "", "methodology": "Visual Inspection", "criteria": "Filter should not be damaged or choked" },
          { "component": "Terminal HEPA filter", "checks": "", "methodology": "Visual Inspection", "criteria": "Filter should not be damaged or choked" },
          { "component": "Chilled water coil", "checks": "", "methodology": "Visual Inspection", "criteria": "No leakages from coil tube and header." },
          { "component": "Hot water coil", "checks": "", "methodology": "Visual Inspection", "criteria": "No leakages from coil tube and header." },
          { "component": "AHU Volume control damper", "checks": "", "methodology": "Visual Inspection", "criteria": "Not damaged; smooth operation." },
          { "component": "AHU drain tray and drain line", "checks": "", "methodology": "Visual Inspection", "criteria": "Clean and clear." },
          { "component": "AHU supply and return Duct", "checks": "", "methodology": "Visual Inspection", "criteria": "No damage/leakages; properly insulated." },
          { "component": "Supply HEPA housing Box", "checks": "", "methodology": "Visual Inspection", "criteria": "No physical damage; VCD smooth; grill fixed properly." },
          { "component": "VFD", "checks": "", "methodology": "Visual Inspection", "criteria": "Fixed properly in VFD panel." },
          { "component": "VFD / Electrical panel", "checks": "", "methodology": "Visual Inspection", "criteria": "No damage; connections proper; cables dressed." }
        ]
      },

      { "type": "heading", "level": 3, "text": "Operational qualification checks: Functional Tests" },
      {
        "type": "paragraph",
        "text": "In order to evaluate correct functioning / operation of the equipment, checks such as start-up check, shut down check, operational checks and safety checks shall be performed."
      },

      {
        "type": "repeat_table",
        "key": "functional_tests",
        "columns": ["Sr. No", "Identification/ Position of valve", "Acceptance criteria", "Check", "Observation", "Engineering Check", "Done by (Engineering)"],
        "row_schema": {
          "sr_no": { "type": "text", "default": "" },
          "valve_position": { "type": "text", "default": "" },
          "criteria": { "type": "text", "default": "" },
          "check": { "type": "checkbox", "default": false },
          "observation": { "type": "text", "default": "" },
          "eng_check": { "type": "checkbox", "default": false },
          "done_by": { "type": "text", "default": "" }
        },
        "default_rows": [
          { "sr_no": "1", "valve_position": "Supply air damper of room terminal boxes and main supply air duct", "criteria": "Open/close properly; locking arrangement available." },
          { "sr_no": "2", "valve_position": "Return air damper of room terminal boxes / return riser ducts and main return air duct", "criteria": "Open/close properly; locking arrangement available." },
          { "sr_no": "3", "valve_position": "Fresh air & Exhaust air damper", "criteria": "Open/close properly; locking arrangement available." },
          { "sr_no": "4", "valve_position": "Fire damper in main return air duct", "criteria": "Should be in open condition." },
          { "sr_no": "5", "valve_position": "Chilled water valve provided in chilled water manifold", "criteria": "Open/close smoothly." },
          { "sr_no": "6", "valve_position": "Hot water valve provided in hot water manifold", "criteria": "Open/close smoothly." },
          { "sr_no": "7", "valve_position": "Chilled water and hot water manifold leakages", "criteria": "No leakages from joints/fittings." },
          { "sr_no": "8", "valve_position": "AHU plenum doors position", "criteria": "Open/close properly for all sections." },
          { "sr_no": "9", "valve_position": "Door gaskets", "criteria": "Provided in all plenum doors." },
          { "sr_no": "10", "valve_position": "Filter fittings in the AHU", "criteria": "Fresh/pre/fine/bleed filters fixed properly; no side leakages." },
          { "sr_no": "11", "valve_position": "Supply grill fittings", "criteria": "Fixed properly from bottom side." },
          { "sr_no": "12", "valve_position": "Return grill fittings", "criteria": "Fixed properly from bottom side." },
          { "sr_no": "13", "valve_position": "Supply and return air duct and duct joints", "criteria": "Fixed properly; no leakages." }
        ]
      },

      { "type": "heading", "level": 3, "text": "Machine Performance" },
      {
        "type": "list",
        "style": "ol_fields",
        "items": [
          { "label": "Switch ON the main power supply of AHU electrical panel:", "field": { "name": "performance_1", "type": "text" } },
          { "label": "Manual / Auto Mode Select selector switch:", "field": { "name": "performance_2", "type": "text" } },
          { "label": "Chilled water supply:", "field": { "name": "performance_3", "type": "text" } },
          { "label": "Hot water supply:", "field": { "name": "performance_4", "type": "text" } },
          { "label": "Auto start AHU:", "field": { "name": "performance_5", "type": "text" } },
          { "label": "Stop AHU:", "field": { "name": "performance_6", "type": "text" } },
          { "label": "AHU blower-motor function:", "field": { "name": "performance_7", "type": "text" } },
          { "label": "DP gauge readings:", "field": { "name": "performance_8", "type": "text" } },
          { "label": "Door safety interlock:", "field": { "name": "performance_9", "type": "text" } },
          { "label": "Interlocking with Dust Extractor:", "field": { "name": "performance_10", "type": "text" } }
        ]
      },

      { "type": "heading", "level": 5, "text": "10.0 DEVIATIONS (IF ANY)" },
      {
        "type": "table",
        "key": "deviations",
        "rows": [
          {
            "type": "header",
            "cells": [
              { "type": "th", "text": "Deviation (If any)" },
              { "type": "th", "text": "Corrective Actions" },
              { "type": "th", "text": "Preventive Actions" }
            ]
          },
          {
            "cells": [
              { "type": "field", "name": "deviation_1", "field_type": "textarea", "rows": 3, "style": "width:100%;" },
              { "type": "field", "name": "corrective_action_1", "field_type": "textarea", "rows": 3, "style": "width:100%;" },
              { "type": "field", "name": "preventive_action_1", "field_type": "textarea", "rows": 3, "style": "width:100%;" }
            ]
          }
        ]
      },

      { "type": "heading", "level": 5, "text": "11.0 SUMMARY & CONCLUSION" },
      {
        "type": "paragraph",
        "text": "Based on thorough review of the available data, it is concluded that, the AHU ID. No. /ENG/AHU-005 meets the Requalification criteria defined in the protocol. The AHU is in good condition and can be operate for regular use."
      },

      { "type": "paragraph", "text": "Attachments" },
      {
        "type": "list",
        "style": "ol_fields",
        "items": [
          { "field": { "name": "attachment_1", "type": "text", "default": "Airflow volume measurement / ACPH" } },
          { "field": { "name": "attachment_2", "type": "text", "default": "HEPA filter Integrity test" } },
          { "field": { "name": "attachment_3", "type": "text", "default": "Non-Viable particulate count test" } },
          { "field": { "name": "attachment_4", "type": "text", "default": "Area Recovery test" } },
          { "field": { "name": "attachment_5", "type": "text", "default": "Airflow Pattern test" } },
          { "field": { "name": "attachment_6", "type": "text", "default": "Dust Extractor CFM measurement" } },
          { "field": { "name": "attachment_7", "type": "text", "default": "Calibration certificates of instrument attached with AHU" } },
          { "field": { "name": "attachment_8", "type": "text", "default": "Calibration certificates of instrument used during requalification of AHU" } },
          { "field": { "name": "attachment_9", "type": "text", "default": "Training certificate of personnel involved from third party" } }
        ]
      },

      { "type": "heading", "level": 5, "text": "12.0 REFERENCES" },
      {
        "type": "paragraph",
        "content": [
          { "type": "text", "text": "Installation & Operational qualification documents:" },
          { "type": "field", "name": "reference_1", "field_type": "text", "class": "input", "default": "/IOQ/AHU/16-001" }
        ]
      },
      {
        "type": "paragraph",
        "content": [
          { "type": "text", "text": "Performance Qualification Document:" },
          { "type": "field", "name": "reference_2", "field_type": "text", "class": "input", "default": "/PQR/AHU/16-001" }
        ]
      },

      { "type": "heading", "level": 5, "text": "13.0 ABBREVIATIONS" },
      {
        "type": "rich_text",
        "html": "AHU - Air Handling Unit<br/>ACPH - Air Changes Per Hour<br/>CFM - Cubic Feet per minute<br/>DQ - Design Qualification<br/>cGMP - Current good Manufacturing practices<br/>MOC - Material of Construction<br/>PIBCV - Pressure Independent Balancing Control Valve<br/>PID - Proportional Integral Derivative<br/>URS - User Requirement Specification<br/>IQ - Installation Qualification<br/>OQ - Operational Qualification<br/>IOQ - Installation & Operational Qualification<br/>PQ - Performance Qualification<br/>PQR - Performance Qualification Report<br/>MM of WC - Millimeter of Water Column<br/>SS - Stainless Steel<br/>GI - Galvanized Iron<br/>VFD - Variable Frequency Drive<br/>KW - Kilo Watt<br/>P&ID - Piping & Instrumentation Diagram<br/>KG/CM2 - Kilogram per centimeter square<br/>°C - Degree Celsius"
      },

      { "type": "heading", "level": 5, "text": "14.0 REVISION HISTORY" },
      {
        "type": "table",
        "key": "revision_history",
        "rows": [
          {
            "type": "header",
            "cells": [
              { "type": "th", "text": "Revision No." },
              { "type": "th", "text": "Effective Date" },
              { "type": "th", "text": "Reason for Revision" }
            ]
          },
          {
            "cells": [
              { "type": "text", "text": "00" },
              { "type": "field", "name": "revision_date", "field_type": "date", "class": "input-date" },
              { "type": "field", "name": "revision_reason", "field_type": "text", "class": "input", "default": "New Document" }
            ]
          }
        ]
      },

      { "type": "heading", "level": 2, "text": "POST-APPROVAL SIGNATURE" },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "PREPARED BY" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_prepared_by_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_prepared_by_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_prepared_by_date", "field_type": "date", "class": "input-date" },
          { "type": "text", "text": "USER DEPARTMENT (ENGINEERING)" }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "CHECKED BY" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_checked_by_engineering_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_checked_by_engineering_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_checked_by_engineering_date", "field_type": "date", "class": "input-date" },
          { "type": "text", "text": "ENGINEERING" }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "USER DEPARTMENT (PRODUCTION)" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_checked_by_production_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_checked_by_production_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_checked_by_production_date", "field_type": "date", "class": "input-date" }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "QUALITY ASSURANCE" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_quality_assurance_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_quality_assurance_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_quality_assurance_date", "field_type": "date", "class": "input-date" }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "VALIDATION" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_validation_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_validation_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_validation_date", "field_type": "date", "class": "input-date" }
        ]
      },

      {
        "type": "paragraph",
        "content": [
          { "type": "label", "text": "APPROVED BY" },
          { "type": "label", "text": "NAME & DESIGNATION" },
          { "type": "field", "name": "post_approved_by_name", "field_type": "text", "class": "input" },
          { "type": "label", "text": "SIGNATURE & DATE" },
          { "type": "field", "name": "post_approved_by_signature", "field_type": "text", "class": "input" },
          { "type": "field", "name": "post_approved_by_date", "field_type": "date", "class": "input-date" },
          { "type": "text", "text": "QUALITY ASSURANCE" }
        ]
      },

      {
        "type": "submit",
        "text": "Review Report",
        "class": "btn"
      }
    ]
  }
}',
    GETDATE(),
    GETDATE()
);

