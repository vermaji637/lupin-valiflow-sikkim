<?php
// database/seeders/FormTemplateSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FormTemplate;

class FormTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $schema = [
            "header" => [
                "title_company" => "Company Name, (Location)",
                "title_main"    => "PERIODIC REQUALIFICATION PROTOCOL AND REPORT",
                "labels" => [
                    "facility_section" => "FACILITY/SECTION",
                    "equipment_name"   => "EQUIPMENT NAME",
                    "equipment_id"     => "EQUIPMENT ID. NO.",
                    "document_no"      => "DOCUMENT NO.",
                    "version"          => "VERSION",
                    "page_no"          => "PAGE NO."
                ],
            ],

            "sections" => [
                [
                    "type"  => "signature_block",
                    "title" => "PRE-APPROVAL SIGNATURE",
                    "tables" => [
                        [
                            "headers" => ["PREPARED BY", "NAME & DESIGNATION", "SIGNATURE & DATE"],
                            "rows" => [
                                ["USER DEPARTMENT (ENGINEERING)", "{AUTH_USER_NAME}", "{SIGNATURE_INPUT}+{DATE_INPUT}"]
                            ]
                        ],
                        [
                            "headers" => ["CHECKED BY", "NAME & DESIGNATION", "SIGNATURE & DATE"],
                            "rows" => [
                                ["ENGINEERING", "{USER_SELECT}", "{SIGNATURE_INPUT}+{DATE_INPUT}"],
                                ["USER DEPARTMENT (PRODUCTION)", "{USER_SELECT}", "{SIGNATURE_INPUT}+{DATE_INPUT}"],
                                ["VALIDATION", "{USER_SELECT}", "{SIGNATURE_INPUT}+{DATE_INPUT}"],
                            ]
                        ],
                        [
                            "headers" => ["APPROVED BY", "NAME & DESIGNATION", "SIGNATURE & DATE"],
                            "rows" => [
                                ["USER / VALIDATION", "{USER_SELECT}", "{SIGNATURE_INPUT}+{DATE_INPUT}"],
                                ["QUALITY ASSURANCE", "{USER_SELECT}", "{SIGNATURE_INPUT}+{DATE_INPUT}"],
                            ]
                        ],
                    ],
                    "extra_line" => "EFFECTIVE DATE: {EFFECTIVE_DATE_INPUT}",
                ],

                [
                    "type" => "toc",
                    "title" => "TABLE OF CONTENTS",
                    "items" => [
                        ["1.0 OBJECTIVE", "3"],
                        ["2.0 SCOPE", "3"],
                        ["3.0 RESPONSIBILITY", "4"],
                        ["4.0 TYPE OF QUALIFICATION", "4"],
                        ["5.0 EQUIPMENT / SYSTEM DETAILS", "5"],
                        ["6.0 PRE-REQUISITES", "7"],
                        ["7.0 APPLICABLE SOPs", "8"],
                        ["8.0 PRE-QUALIFICATION CHECKS", "8"],
                        ["9.0 REQUALIFICATION CHECKS", "12"],
                        ["10.0 DEVIATIONS (IF ANY)", "21"],
                        ["11.0 SUMMARY & CONCLUSION", "21"],
                        ["12.0 REFERENCES", "22"],
                        ["13.0 ABBREVIATIONS", "22"],
                        ["14.0 REVISION HISTORY", "23"],
                    ]
                ],

                [
                    "type" => "text",
                    "title" => "1.0 OBJECTIVE",
                    "paragraphs" => [
                        "The protocol provides guidelines for the requalification of Air Handling Unit ID. No. {EQUIPMENT_ID} in the facility of {COMPANY_NAME} to establish high degree of assurance that the equipment is maintained at a qualified stage since its initial installation and is fit for its intended use when operated as per applicable SOP."
                    ]
                ],

                [
                    "type" => "text",
                    "title" => "2.0 SCOPE",
                    "paragraphs" => [
                        "This protocol is applicable for Air Handling Unit as details given in below table. This protocol provides directions / procedure for requalification to provide documented evidence that Air Handling Unit is operated in accordance with the requirements of the product, process, and safety and cGMP norms. The subjected equipment is identified as Air Handling Unit ID. No. {EQUIPMENT_ID} located at Service Floor."
                    ]
                ],

                [
                    "type" => "key_value_table",
                    "title" => "Equipment Details",
                    "headers" => ["Details", "Description"],
                    "rows" => [
                        ["Tag. No.", "{INPUT:tag_no|/ENG/AHU-005|text}"],
                    ]
                ],

                [
                    "type" => "bullets",
                    "title" => "3.0 RESPONSIBILITY",
                    "items" => [
                        "Engineering department is responsible to prepare & review the re-qualification protocol in consultation with Validation / QA, execution of the requalification and preparation of re-qualification summary report.",
                        "User and engineering department shall execute the re-qualification activity.",
                        "Engineering department is responsible to review the protocol for adequacy and accuracy, and to provide the equipment maintenance history.",
                        "Validation department is responsible to review the requalification protocol and summary report.",
                        "Quality Assurance is responsible to provide guidance, wherever required to the related departments, review & approval of the protocol & requalification report."
                    ]
                ],

                [
                    "type"  => "text_with_inputs",
                    "title" => "4.0 TYPE OF QUALIFICATION",
                    "content" => [
                        "As the qualification of Air Handling Unit having I.D. {EQUIPMENT_ID} has been completed on {INPUT:qualification_date|2017-01-13|date} as per document no. {INPUT:qualification_document_no|/PQR/AHU/16-001|text}. Hence periodic re-qualification is to be performed as per frequency defined in SOP."
                    ],
                ],

                [
                    "type"  => "table",
                    "title" => "Qualification History",
                    "headers" => [
                        "Qualification",
                        "Details of qualification (Report no and approval date)"
                    ],
                    "rows" => [
                        [
                            "Initial Qualification (PQ)",
                            "<b>Report No.</b> {INPUT:initial_qualification_report_no|/PQR/AHU/16-001|text}<br/><b>Approval Date</b> {INPUT:initial_qualification_approval_date|2017-01-13|date}"
                        ],
                        [
                            "Other qualifications after initial",
                            "{INPUT:other_qualifications|NA|text}"
                        ]
                    ]
                ],

                [
                    "type"  => "heading",
                    "title" => "5.0 EQUIPMENT / SYSTEM DETAILS",
                ],
                [
                    "type"  => "heading_small",
                    "title" => "Details of Air Handling Unit",
                ],

                [
                    "type" => "dynamic_table",
                    "title" => "Accessories Table",
                    "table_id" => "accessoriesTable",
                    "add_rows_button" => true,
                    "headers" => ["DETAILS", "DESCRIPTION"],
                    "rows" => [
                        ["Name of Equipment", "{INPUT:equipment_name_detail|Air Handling Unit|text}"]
                    ],
                    "row_template" => ["{INPUT:acc_detail[]||text}", "{INPUT:acc_desc[]||text}"]
                ],

                [
                    "type" => "dynamic_table",
                    "title" => "Connected Accessories",
                    "table_id" => "accessories",
                    "add_rows_button" => true,
                    "headers" => ["Connected Accessories", "Description"],
                    "rows" => [
                        ["Blower / motor", "{INPUT:blower_motor|Plug type Blower ... static pressure of 135 MM of WC.|text}"]
                    ],
                    "row_template" => ["{INPUT:connected_accessory[]||text}", "{INPUT:connected_accessory_desc[]||text}"]
                ],

                [
                    "type"  => "text",
                    "title" => "6.0 PRE-REQUISITES",
                    "paragraphs" => [
                        "Protocol shall be approved with identification of all tests to be carried out ... reviewed and approved by all departments."
                    ]
                ],

                [
                    "type"  => "table",
                    "title" => "7.0 APPLICABLE STANDARD OPERATING PROCEDURE (SOP's)",
                    "headers" => ["Sr. No.", "Title of the SOP", "SOP No.", "Effective Date"],
                    "rows" => [
                        ["1", "{INPUT:sop_title_1||text}", "{INPUT:sop_no_1||text}", "{INPUT:sop_date_1||date}"],
                        ["2", "{INPUT:sop_title_2||text}", "{INPUT:sop_no_2||text}", "{INPUT:sop_date_2||date}"],
                        ["3", "{INPUT:sop_title_3||text}", "{INPUT:sop_no_3||text}", "{INPUT:sop_date_3||date}"],
                        ["4", "{INPUT:sop_title_4||text}", "{INPUT:sop_no_4||text}", "{INPUT:sop_date_4||date}"],
                    ],
                ],

                [
                    "type"  => "heading",
                    "title" => "8.0 PRE-QUALIFICATION CHECKS",
                ],
                [
                    "type"  => "heading_small",
                    "title" => "Calibrations of Instruments",
                ],
                [
                    "type"  => "text",
                    "title" => null,
                    "paragraphs" => [
                        "Verify the calibration status of all the instruments and record the details below. Attach copies of certificates."
                    ]
                ],

                [
                    "type" => "table",
                    "title" => "Calibrations of Instruments",
                    "headers" => ["Sr. No.", "Instrument Name", "Instrument ID", "Date of Calibration", "Due Date", "Checked By (Sign.)"],
                    "rows" => [
                        ["1", "Differential Pressure Gauge (across pre filter)", "{INPUT:inst_id_1||text}", "{INPUT:inst_cal_date_1||date}", "{INPUT:inst_due_date_1||date}", "{INPUT:inst_checked_by_1||text}"],
                        ["2", "Differential Pressure Gauge (across fine filter)", "{INPUT:inst_id_2||text}", "{INPUT:inst_cal_date_2||date}", "{INPUT:inst_due_date_2||date}", "{INPUT:inst_checked_by_2||text}"],
                        ["3", "Pressure gauge (chilled water inlet line)", "{INPUT:inst_id_3||text}", "{INPUT:inst_cal_date_3||date}", "{INPUT:inst_due_date_3||date}", "{INPUT:inst_checked_by_3||text}"],
                        ["4", "Pressure gauge (chilled water outlet line)", "{INPUT:inst_id_4||text}", "{INPUT:inst_cal_date_4||date}", "{INPUT:inst_due_date_4||date}", "{INPUT:inst_checked_by_4||text}"],
                        ["5", "Pressure gauge (hot water inlet line)", "{INPUT:inst_id_5||text}", "{INPUT:inst_cal_date_5||date}", "{INPUT:inst_due_date_5||date}", "{INPUT:inst_checked_by_5||text}"],
                        ["6", "Pressure gauge (hot water outlet line)", "{INPUT:inst_id_6||text}", "{INPUT:inst_cal_date_6||date}", "{INPUT:inst_due_date_6||date}", "{INPUT:inst_checked_by_6||text}"],
                        ["7", "Temperature gauge (chilled water inlet line)", "{INPUT:inst_id_7||text}", "{INPUT:inst_cal_date_7||date}", "{INPUT:inst_due_date_7||date}", "{INPUT:inst_checked_by_7||text}"],
                        ["8", "Temperature gauge (chilled water outlet line)", "{INPUT:inst_id_8||text}", "{INPUT:inst_cal_date_8||date}", "{INPUT:inst_due_date_8||date}", "{INPUT:inst_checked_by_8||text}"],
                        ["9", "Temperature gauge (hot water inlet line)", "{INPUT:inst_id_9||text}", "{INPUT:inst_cal_date_9||date}", "{INPUT:inst_due_date_9||date}", "{INPUT:inst_checked_by_9||text}"],
                        ["10", "Temperature gauge (hot water outlet line)", "{INPUT:inst_id_10||text}", "{INPUT:inst_cal_date_10||date}", "{INPUT:inst_due_date_10||date}", "{INPUT:inst_checked_by_10||text}"],
                        ["11", "Temperature / RH transmitter", "{INPUT:inst_id_11||text}", "{INPUT:inst_cal_date_11||date}", "{INPUT:inst_due_date_11||date}", "{INPUT:inst_checked_by_11||text}"],
                        ["12", "Differential pressure Transmitter", "{INPUT:inst_id_12||text}", "{INPUT:inst_cal_date_12||date}", "{INPUT:inst_due_date_12||date}", "{INPUT:inst_checked_by_12||text}"],
                    ],
                ],

                [
                    "type" => "table",
                    "title" => "Dust Extractor",
                    "headers" => ["Sr. No.", "Instrument Name", "Instrument ID", "Date of Calibration", "Due Date", "Checked By"],
                    "rows" => [
                        ["1", "Differential Pressure Gauge (across Cartridge Filter)", "{INPUT:dust_inst_id_1||text}", "{INPUT:dust_inst_cal_date_1||date}", "{INPUT:dust_inst_due_date_1||date}", "{INPUT:dust_inst_checked_by_1||text}"],
                        ["2", "Differential Pressure Gauge (across fine filter)", "{INPUT:dust_inst_id_2||text}", "{INPUT:dust_inst_cal_date_2||date}", "{INPUT:dust_inst_due_date_2||date}", "{INPUT:dust_inst_checked_by_2||text}"],
                        ["3", "Differential Pressure Gauge (across HEPA filter)", "{INPUT:dust_inst_id_3||text}", "{INPUT:dust_inst_cal_date_3||date}", "{INPUT:dust_inst_due_date_3||date}", "{INPUT:dust_inst_checked_by_3||text}"],
                    ]
                ],

                [
                    "type" => "table",
                    "title" => "Preventive Maintenance Details",
                    "headers" => ["Sr. No.", "Equipment Name / ID", "Done on", "Due on", "Checked by (Engineering) (Sign./Date)"],
                    "rows" => [
                        ["1", "{INPUT:pm_equipment_1||text}", "{INPUT:pm_done_date_1||date}", "{INPUT:pm_due_date_1||date}", "{INPUT:pm_checked_by_1||text}"],
                        ["2", "{INPUT:pm_equipment_2||text}", "{INPUT:pm_done_date_2||date}", "{INPUT:pm_due_date_2||date}", "{INPUT:pm_checked_by_2||text}"],
                    ]
                ],

                [
                    "type" => "inputs_list",
                    "title" => "Any other specific tests",
                    "items" => [
                        "{INPUT:specific_test_1|Airflow volume measurement / ACPH|text}",
                        "{INPUT:specific_test_2|HEPA filter Integrity test|text}",
                        "{INPUT:specific_test_3|Non-Viable particulate count test|text}",
                        "{INPUT:specific_test_4|Area Recovery test|text}",
                        "{INPUT:specific_test_5|Airflow Pattern test|text}",
                        "{INPUT:specific_test_6|Dust Extractor CFM measurement|text}",
                    ]
                ],

                [
                    "type" => "verification_blocks",
                    "title" => "Verification with the current P & ID",
                    "blocks" => [
                        [
                            "history_from" => "{INPUT:history_from_date_1||date}",
                            "history_to" => "{INPUT:history_to_date_1||date}",
                            "reviewed_by" => "{INPUT:history_reviewed_by_1||text}",
                            "recommendation" => "{INPUT:history_recommendation_1||text}"
                        ],
                        [
                            "history_from" => "{INPUT:history_from_date_2||date}",
                            "history_to" => "{INPUT:history_to_date_2||date}",
                            "reviewed_by" => "{INPUT:history_reviewed_by_2||text}",
                            "recommendation" => "{INPUT:history_recommendation_2||text}"
                        ],
                    ]
                ],

                [
                    "type" => "textarea",
                    "title" => "History of Equipment / system",
                    "name" => "equipment_history",
                    "rows" => 4
                ],

                [
                    "type" => "text",
                    "title" => "Review of Change Controls related to the Equipment / system",
                    "paragraphs" => [
                        "Review the Change Controls related to the Equipment / system under requalification and summarize;"
                    ]
                ],
                [
                    "type" => "table",
                    "title" => "Change Controls",
                    "headers" => ["S. No.", "Ref. Change Control No.", "Summary of Changes"],
                    "rows" => [
                        ["1", "{INPUT:change_control_no_1||text}", "{INPUT:change_summary_1||text}"],
                        ["2", "{INPUT:change_control_no_2||text}", "{INPUT:change_summary_2||text}"],
                    ]
                ],
                [
                    "type" => "textarea",
                    "title" => "Remarks / Recommendation (if any)",
                    "name" => "change_remarks",
                    "rows" => 3
                ],
                [
                    "type" => "input",
                    "title" => "Reviewed By (Sign/Date)",
                    "name" => "change_reviewed_by",
                    "input_type" => "text"
                ],

                [
                    "type" => "table",
                    "title" => "9.0 REQUALIFICATION CHECKS - Installation Re-qualification checks",
                    "headers" => ["Checks", "Acceptance criteria", "Observation", "Done by (Engineering)"],
                    "rows" => [
                        ["Electrical connections", "All the electrical connection in the panel should be connected properly", "{INPUT:check_electrical_obs||text}", "{INPUT:check_electrical_done_by||text}"],
                        ["Check chilled water connections", "Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: 2 to 8°C", "{INPUT:check_chilled_water_obs||text}", "{INPUT:check_chilled_water_done_by||text}"],
                        ["Check hot water connections", "Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: ≥ 35.0°C", "{INPUT:check_hot_water_obs||text}", "{INPUT:check_hot_water_done_by||text}"],
                        ["Leveling/ Centering/ Alignment", "Should be properly leveled / centered / aligned", "{INPUT:check_leveling_obs||text}", "{INPUT:check_leveling_done_by||text}"],
                        ["Access and Clearance", "Adequate space is available for accessing the equipment for operation, cleaning & maintenance", "{INPUT:check_access_obs||text}", "{INPUT:check_access_done_by||text}"],
                    ]
                ],

                [
                    "type" => "table",
                    "title" => "General Checks: Verification of major components of equipment",
                    "headers" => ["Major components","Checks","Methodology","Acceptance criteria","Observation/ Result","Done by"],
                    "rows" => [
                        ["Machine frame and body part", "Check any physical damage to the machine frame & body part.", "Visual Inspection", "No physical damage.", "{INPUT:comp_frame_obs||text}", "{INPUT:comp_frame_done_by||text}"],
                        ["Fresh air filter", "", "Visual Inspection", "Filter should not be damaged or choked", "{INPUT:comp_fresh_filter_obs||text}", "{INPUT:comp_fresh_filter_done_by||text}"],
                        ["Pre filter", "", "Visual Inspection", "Filter should not be damaged or choked", "{INPUT:comp_pre_filter_obs||text}", "{INPUT:comp_pre_filter_done_by||text}"],
                        ["Fine filter", "", "Visual Inspection", "Filter should not be damaged or choked", "{INPUT:comp_fine_filter_obs||text}", "{INPUT:comp_fine_filter_done_by||text}"],
                        ["Bleed air filter", "", "Visual Inspection", "Filter should not be damaged or choked", "{INPUT:comp_bleed_filter_obs||text}", "{INPUT:comp_bleed_filter_done_by||text}"],
                        ["Terminal HEPA filter", "", "Visual Inspection", "Filter should not be damaged or choked", "{INPUT:comp_hepa_filter_obs||text}", "{INPUT:comp_hepa_filter_done_by||text}"],
                        ["Chilled water coil", "", "Visual Inspection", "No leakages from coil tube and header.", "{INPUT:comp_chilled_coil_obs||text}", "{INPUT:comp_chilled_coil_done_by||text}"],
                        ["Hot water coil", "", "Visual Inspection", "No leakages from coil tube and header.", "{INPUT:comp_hot_coil_obs||text}", "{INPUT:comp_hot_coil_done_by||text}"],
                        ["AHU Volume control damper", "", "Visual Inspection", "Not damaged; smooth operation.", "{INPUT:comp_damper_obs||text}", "{INPUT:comp_damper_done_by||text}"],
                        ["AHU drain tray and drain line", "", "Visual Inspection", "Clean and clear.", "{INPUT:comp_drain_obs||text}", "{INPUT:comp_drain_done_by||text}"],
                        ["AHU supply and return Duct", "", "Visual Inspection", "No damage/leakages; properly insulated.", "{INPUT:comp_duct_obs||text}", "{INPUT:comp_duct_done_by||text}"],
                        ["Supply HEPA housing Box", "", "Visual Inspection", "No physical damage; VCD smooth; grill fixed properly.", "{INPUT:comp_hepa_box_obs||text}", "{INPUT:comp_hepa_box_done_by||text}"],
                        ["VFD", "", "Visual Inspection", "Fixed properly in VFD panel.", "{INPUT:comp_vfd_obs||text}", "{INPUT:comp_vfd_done_by||text}"],
                        ["VFD / Electrical panel", "", "Visual Inspection", "No damage; connections proper; cables dressed.", "{INPUT:comp_electrical_panel_obs||text}", "{INPUT:comp_electrical_panel_done_by||text}"],
                    ]
                ],

                [
                    "type" => "text",
                    "title" => "Operational qualification checks: Functional Tests",
                    "paragraphs" => [
                        "In order to evaluate correct functioning / operation of the equipment, checks such as start-up check, shut down check, operational checks and safety checks shall be performed."
                    ]
                ],
                [
                    "type" => "table",
                    "title" => "Operational Tests",
                    "headers" => ["Sr. No","Identification/ Position of valve","Acceptance criteria","Observation","Done by (Engineering)"],
                    "rows" => [
                        ["1", "Supply air damper of room terminal boxes and main supply air duct", "Open/close properly; locking arrangement available.", "{INPUT:op_supply_damper_obs||text}", "{INPUT:op_supply_damper_done_by||text}"],
                        ["2", "Return air damper of room terminal boxes / return riser ducts and main return air duct", "Open/close properly; locking arrangement available.", "{INPUT:op_return_damper_obs||text}", "{INPUT:op_return_damper_done_by||text}"],
                        ["3", "Fresh air & Exhaust air damper", "Open/close properly; locking arrangement available.", "{INPUT:op_fresh_damper_obs||text}", "{INPUT:op_fresh_damper_done_by||text}"],
                        ["4", "Fire damper in main return air duct", "Should be in open condition.", "{INPUT:op_fire_damper_obs||text}", "{INPUT:op_fire_damper_done_by||text}"],
                        ["5", "Chilled water valve provided in chilled water manifold", "Open/close smoothly.", "{INPUT:op_chilled_valve_obs||text}", "{INPUT:op_chilled_valve_done_by||text}"],
                        ["6", "Hot water valve provided in hot water manifold", "Open/close smoothly.", "{INPUT:op_hot_valve_obs||text}", "{INPUT:op_hot_valve_done_by||text}"],
                        ["7", "Chilled water and hot water manifold leakages", "No leakages from joints/fittings.", "{INPUT:op_manifold_obs||text}", "{INPUT:op_manifold_done_by||text}"],
                        ["8", "AHU plenum doors position", "Open/close properly for all sections.", "{INPUT:op_plenum_doors_obs||text}", "{INPUT:op_plenum_doors_done_by||text}"],
                        ["9", "Door gaskets", "Provided in all plenum doors.", "{INPUT:op_door_gaskets_obs||text}", "{INPUT:op_door_gaskets_done_by||text}"],
                        ["10", "Filter fittings in the AHU", "Fresh/pre/fine/bleed filters fixed properly; no side leakages.", "{INPUT:op_filter_fittings_obs||text}", "{INPUT:op_filter_fittings_done_by||text}"],
                        ["11", "Supply grill fittings", "Fixed properly from bottom side.", "{INPUT:op_supply_grill_obs||text}", "{INPUT:op_supply_grill_done_by||text}"],
                        ["12", "Return grill fittings", "Fixed properly from bottom side.", "{INPUT:op_return_grill_obs||text}", "{INPUT:op_return_grill_done_by||text}"],
                        ["13", "Supply and return air duct and duct joints", "Fixed properly; no leakages.", "{INPUT:op_duct_joints_obs||text}", "{INPUT:op_duct_joints_done_by||text}"],
                    ]
                ],

                [
                    "type" => "ordered_inputs",
                    "title" => "Machine Performance",
                    "items" => [
                        ["Switch ON the main power supply of AHU electrical panel", "{INPUT:performance_1||text}"],
                        ["Manual / Auto Mode Select selector switch", "{INPUT:performance_2||text}"],
                        ["Chilled water supply", "{INPUT:performance_3||text}"],
                        ["Hot water supply", "{INPUT:performance_4||text}"],
                        ["Auto start AHU", "{INPUT:performance_5||text}"],
                        ["Stop AHU", "{INPUT:performance_6||text}"],
                        ["AHU blower-motor function", "{INPUT:performance_7||text}"],
                        ["DP gauge readings", "{INPUT:performance_8||text}"],
                        ["Door safety interlock", "{INPUT:performance_9||text}"],
                        ["Interlocking with Dust Extractor", "{INPUT:performance_10||text}"],
                    ]
                ],

                [
                    "type" => "table",
                    "title" => "10.0 DEVIATIONS (IF ANY)",
                    "headers" => ["Deviation (If any)", "Corrective Actions", "Preventive Actions"],
                    "rows" => [
                        [
                            "{TEXTAREA:deviation_1|3}",
                            "{TEXTAREA:corrective_action_1|3}",
                            "{TEXTAREA:preventive_action_1|3}"
                        ]
                    ]
                ],

                [
                    "type" => "text",
                    "title" => "11.0 SUMMARY & CONCLUSION",
                    "paragraphs" => [
                        "Based on thorough review of the available data, it is concluded that, the AHU ID. No. {EQUIPMENT_ID} meets the Requalification criteria defined in the protocol. The AHU is in good condition and can be operate for regular use."
                    ]
                ],

                [
                    "type" => "abbreviations",
                    "title" => "13.0 ABBREVIATIONS",
                    "items" => [
                        ["AHU", "Air Handling Unit"],
                        ["ACPH", "Air Changes Per Hour"],
                        ["CFM", "Cubic Feet per minute"],
                        ["IQ", "Installation Qualification"],
                        ["OQ", "Operational Qualification"],
                        ["PQ", "Performance Qualification"],
                        ["cGMP", "Current good Manufacturing practices"],
                    ]
                ],
            ],
        ];

        FormTemplate::updateOrCreate(
            ['form_key' => 'ahu_requalification'],
            ['schema_json' => $schema] // casts will store as JSON
        );
    }
}
