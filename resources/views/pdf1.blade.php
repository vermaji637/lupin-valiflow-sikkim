



   <div id="content" class="editable" contenteditable="false">
        <form action="{{ route('createoffer') }}" method="POST">
            @csrf
            <table>
                <tr>
                    <td rowspan="4" style="width:15%; text-align:center;">
                        <img src="http://localhost:81/proposal_pdf/public/admin/img/lupin1.png" alt="Professional company logo with modern design featuring engineering elements and corporate branding in blue and gray colors" style="height:80px;width:auto;"/>
                    </td>
                    <td colspan="3" class="center header-title">
                        <input class="input input-lg center" name="company_name" value="Company Name, (Location)" />
                    </td>
                </tr>
                <tr>
                    <td colspan="3" class="center sub-title">PERIODIC REQUALIFICATION PROTOCOL AND REPORT</td>
                </tr>
                <tr>
                    <td style="width:25%;"><strong>FACILITY/SECTION</strong><br><input class="input" name="facility_section" id="facility"/></td>
                    <td style="width:30%;"><strong>EQUIPMENT NAME</strong><br><input class="input" name="equipment_name" id="equipment_name" value="Air Handling Unit" /></td>
                    <td style="width:30%;"><strong>EQUIPMENT ID. NO.</strong><br><input class="input" name="equipment_id" id="equipment_id" value="/ENG/AHU-005" /></td>
                </tr>
                <tr>
                    <td><strong>DOCUMENT NO.</strong><br><select class="input" id ="document_no" name="document_id">
        <option value="">-- Select Document  --</option>
        @foreach($documents as $type)
            <option value="{{ $type->id }}">{{ $type->title }}</option>
        @endforeach

    </select><input class="input" name="document_no" /></td>
                    <td><strong>VERSION</strong><br><input class="input" name="version" value="00" /></td>
                    <td><strong>PAGE NO.</strong> <span>1 of 25</span></td>
                </tr>
            </table>

            <h3>PRE-APPROVAL SIGNATURE</h3>
            <table>
                <tr class="section-title">
                    <th style="width:30%;">PREPARED BY</th>
                    <th style="width:40%;">NAME & DESIGNATION</th>
                    <th style="width:30%;">SIGNATURE & DATE</th>
                </tr>
                <tr>
                    <td>USER DEPARTMENT (ENGINEERING)</td>
                    <td><input class="input" name="prepared_by_name" value="{{ Auth::user()->fullname }}" /></td>
                    <td>
                        <input class="input" name="prepared_by_signature" placeholder="Signature" /><br>
                        <input class="input-date" name="prepared_by_date" type="date" />
                    </td>
                </tr>
            </table>

            <table>
                <tr class="section-title">
                    <th style="width:30%;">CHECKED BY</th>
                    <th style="width:40%;">NAME & DESIGNATION</th>
                    <th style="width:30%;">SIGNATURE & DATE</th>
                </tr>
                <tr>
                    <td>ENGINEERING</td>
                    <td>  <select  name="checked_by_engineering_name" class="form-control form-control">
    <option value="">Select user</option>

               @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->first_name }}</option>

            @endforeach
</select></td>
                    <td>
                      <input class="input" name="checked_by_engineering_signature" placeholder="Signature" /><br><input class="input-date" name="checked_by_engineering_date" type="date" /></td>
                </tr>
                <tr>
                    <td>USER DEPARTMENT (PRODUCTION)</td>
                    <td><select  name="checked_by_production_name" class="form-control form-control">
    <option value="">Select user</option>

               @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->first_name }}</option>

            @endforeach
</select></td>
                    <td><input class="input" name="checked_by_production_signature" placeholder="Signature" /><br><input class="input-date" name="checked_by_production_date" type="date" /></td>
                </tr>
                <tr>
                    <td>VALIDATION</td>
                    <td><select  name="checked_by_validation_name" class="form-control form-control">
    <option value="">Select user</option>

               @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->first_name }}</option>

            @endforeach
</select></td>
                    <td><input class="input" name="checked_by_validation_signature" placeholder="Signature" /><br><input class="input-date" name="checked_by_validation_date" type="date" /></td>
                </tr>
            </table>

            <table>
                <tr class="section-title">
                    <th style="width:30%;">APPROVED BY</th>
                    <th style="width:40%;">NAME & DESIGNATION</th>
                    <th style="width:30%;">SIGNATURE & DATE</th>
                </tr>
                <tr>
                    <td>USER / VALIDATION</td>
                    <td>
                  <select  name="approved_by_user_name" class="form-control form-control">
    <option value="">Select user</option>

               @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->first_name }}</option>

            @endforeach
</select></td>
                    <td><input class="input" name="approved_by_user_signature" placeholder="Signature" /><br><input class="input-date" name="approved_by_user_date" type="date" /></td>
                </tr>
                <tr>
                    <td>QUALITY ASSURANCE</td>
                    <td>  <select  name="approved_by_quality_name" class="form-control form-control">
    <option value="">Select user</option>

               @foreach($users as $user)
        <option value="{{ $user->id }}">{{ $user->first_name }}</option>

            @endforeach
</select></td>
                    <td><input class="input" name="approved_by_quality_signature" placeholder="Signature" /><br><input class="input-date" name="approved_by_quality_date" type="date" /></td>
                </tr>
            </table>

            <p><b>EFFECTIVE DATE:</b> <input class="input-date" name="effective_date" type="date" /></p>

            <h3>TABLE OF CONTENTS</h3>
            <table class="noborder">
                <tr>
                    <td>1.0 OBJECTIVE</td><td></td><td class="page">3</td>
                </tr>
                <tr>
                    <td>2.0 SCOPE</td><td></td><td class="page">3</td>
                </tr>
                <tr>
                    <td>3.0 RESPONSIBILITY</td><td></td><td class="page">4</td>
                </tr>
                <tr>
                    <td>4.0 TYPE OF QUALIFICATION</td><td></td><td class="page">4</td>
                </tr>
                <tr>
                    <td>5.0 EQUIPMENT / SYSTEM DETAILS</td><td></td><td class="page">5</td>
                </tr>
                <tr>
                    <td>6.0 PRE-REQUISITES</td><td></td><td class="page">7</td>
                </tr>
                <tr>
                    <td>7.0 APPLICABLE SOPs</td><td></td><td class="page">8</td>
                </tr>
                <tr>
                    <td>8.0 PRE-QUALIFICATION CHECKS</td><td></td><td class="page">8</td>
                </tr>
                <tr>
                    <td>9.0 REQUALIFICATION CHECKS</td><td></td><td class="page">12</td>
                </tr>
                <tr>
                    <td>10.0 DEVIATIONS (IF ANY)</td><td></td><td class="page">21</td>
                </tr>
                <tr>
                    <td>11.0 SUMMARY & CONCLUSION</td><td></td><td class="page">21</td>
                </tr>
                <tr>
                    <td>12.0 REFERENCES</td><td></td><td class="page">22</td>
                </tr>
                <tr>
                    <td>13.0 ABBREVIATIONS</td><td></td><td class="page">22</td>
                </tr>
                <tr>
                    <td>14.0 REVISION HISTORY</td><td></td><td class="page">23</td>
                </tr>
            </table>

            <h5><strong>1.0 OBJECTIVE</strong></h5>
            <p>The protocol provides guidelines for the requalification of Air Handling Unit ID. No. <span class="documentID">/ENG/AHU-005</span> in the facility of Company Name Ltd. Location to establish high degree of assurance that the equipment is maintained at a qualified stage since its initial installation and is fit for its intended use when operated as per applicable SOP.</p>

            <h5><strong>2.0 SCOPE</strong></h5>
            <p>This protocol is applicable for Air Handling Unit as details given in below table. This protocol provides directions / procedure for requalification to provide documented evidence that Air Handling Unit is operated in accordance with the requirements of the product, process, and safety and cGMP norms. The subjected equipment is identified as Air Handling Unit ID. No. <span class="documentID">/ENG/AHU-005 </span> located at Service Floor.</p>

            <table id="detailsTable">
                <tr><th>Details</th><th>Description</th></tr>
                <tr><td>Tag. No.</td><td><input class="input documentID" name="tag_no" value="/ENG/AHU-005" /></td></tr>


            </table>
            <input type="number" id="rowCountDetails" value="1" min="1" style="width:60px;">
<button class="btn btn-primary pull-right" type="button" onclick="addMultipleRows('detailsTable','DETAIL','DESCRIPTION','rowCountDetails')"> Add Rows</button>

            <h5><strong>3.0 RESPONSIBILITY</strong></h5>
            <ul>
                <li>Engineering department is responsible to prepare & review the re-qualification protocol in consultation with Validation / QA, execution of the requalification and preparation of re-qualification summary report.</li>
                <li>User and engineering department shall execute the re-qualification activity.</li>
                <li>Engineering department is responsible to review the protocol for adequacy and accuracy, and to provide the equipment maintenance history.</li>
                <li>Validation department is responsible to review the requalification protocol and summary report.</li>
                <li>Quality Assurance is responsible to provide guidance, wherever required to the related departments, review & approval of the protocol & requalification report.</li>
            </ul>

            <h5><strong>4.0 TYPE OF QUALIFICATION</strong></h5>
            <p>As the qualification of Air Handling Unit having I.D. <span class="documentID">/ENG/AHU-005 </span>has been completed on <input class="input-date" name="qualification_date" type="date" value="2017-01-13"/> as per document no. <input class="input" name="qualification_document_no" value="/PQR/AHU/16-001"/>. Hence periodic re-qualification is to be performed as per frequency defined in SOP.</p>

            <h3>Qualification History</h3>
            <table>
                <tr><th>Qualification</th><th>Details of qualification (Report no and approval date)</th></tr>
                <tr><td>Initial Qualification (PQ)</td><td><b>Report No.</b> <input class="input" name="initial_qualification_report_no" value="/PQR/AHU/16-001"/><br/><b>Approval Date</b> <input class="input-date" name="initial_qualification_approval_date" type="date" value="2017-01-13"/></td></tr>
                <tr><td>Other qualifications after initial</td><td><input class="input" name="other_qualifications" value="NA"/></td></tr>
            </table>

            <h5><strong>5.0 EQUIPMENT / SYSTEM DETAILS</strong></h5>
            <h6>Details of Air Handling Unit</h6>
            <table id="accessoriesTable">

                <tr><th>DETAILS</th><th>DESCRIPTION</th></tr>
                <tr><td>Name of Equipment</td><td><input class="input" name="equipment_name_detail" value="Air Handling Unit"/></td></tr>

            </table>
            <input type="number" id="rowCountAccessories" value="1" min="1" style="width:60px;">
<button class="btn btn-primary pull-right" type="button" onclick="addMultipleRows('accessoriesTable','Accessory','Description','rowCountAccessories')"> Add Rows</button>
            <table id="accessories">
                <tr><th>Connected Accessories</th><th>Description</th></tr>
                <tr><td>Blower / motor</td><td><input class="input" name="blower_motor" value="Plug type Blower ... static pressure of 135 MM of WC."/></td></tr>

            </table>
<input type="number" id="rowCountDetails1" value="1" min="1" style="width:60px;">
<button class="btn btn-primary pull-right" type="button" onclick="addMultipleRows('accessories','DETAIL','DESCRIPTION','rowCountDetails1')">Add Rows</button>
            <h5><strong>6.0 PRE-REQUISITES</strong></h5>
            <p>Protocol shall be approved with identification of all tests to be carried out ... reviewed and approved by all departments.</p>

            <h5><strong>7.0 APPLICABLE STANDARD OPERATING PROCEDURE (SOP's)</strong></h5>
            <table>
                <tr><th>Sr. No.</th><th>Title of the SOP</th><th>SOP No.</th><th>Effective Date</th></tr>
                <tr><td>1</td><td><input class="input" name="sop_title_1"/></td><td><input class="input" name="sop_no_1"/></td><td><input class="input-date" name="sop_date_1" type="date"/></td></tr>
                <tr><td>2</td><td><input class="input" name="sop_title_2"/></td><td><input class="input" name="sop_no_2"/></td><td><input class="input-date" name="sop_date_2" type="date"/></td></tr>
                <tr><td>3</td><td><input class="input" name="sop_title_3"/></td><td><input class="input" name="sop_no_3"/></td><td><input class="input-date" name="sop_date_3" type="date"/></td></tr>
                <tr><td>4</td><td><input class="input" name="sop_title_4"/></td><td><input class="input" name="sop_no_4"/></td><td><input class="input-date" name="sop_date_4" type="date"/></td></tr>
            </table>

            <h5><strong>8.0 PRE-QUALIFICATION CHECKS</strong></h5>
            <h3>Calibrations of Instruments</h3>
            <p>Verify the calibration status of all the instruments and record the details below. Attach copies of certificates.</p>

            <table>
                <tr><th>Sr. No.</th><th>Instrument Name</th><th>Instrument ID</th><th>Date of Calibration</th><th>Due Date</th><th>Checked By (Sign.)</th></tr>
                <tr><td>1</td><td>Differential Pressure Gauge (across pre filter)</td><td><input class="input" name="inst_id_1"/></td><td><input class="input-date" name="inst_cal_date_1" type="date"/></td><td><input class="input-date" name="inst_due_date_1" type="date"/></td><td><input class="input" name="inst_checked_by_1"/></td></tr>
                <tr><td>2</td><td>Differential Pressure Gauge (across fine filter)</td><td><input class="input" name="inst_id_2"/></td><td><input class="input-date" name="inst_cal_date_2" type="date"/></td><td><input class="input-date" name="inst_due_date_2" type="date"/></td><td><input class="input" name="inst_checked_by_2"/></td></tr>
                <tr><td>3</td><td>Pressure gauge (chilled water inlet line)</td><td><input class="input" name="inst_id_3"/></td><td><input class="input-date" name="inst_cal_date_3" type="date"/></td><td><input class="input-date" name="inst_due_date_3" type="date"/></td><td><input class="input" name="inst_checked_by_3"/></td></tr>
                <tr><td>4</td><td>Pressure gauge (chilled water outlet line)</td><td><input class="input" name="inst_id_4"/></td><td><input class="input-date" name="inst_cal_date_4" type="date"/></td><td><input class="input-date" name="inst_due_date_4" type="date"/></td><td><input class="input" name="inst_checked_by_4"/></td></tr>
                <tr><td>5</td><td>Pressure gauge (hot water inlet line)</td><td><input class="input" name="inst_id_5"/></td><td><input class="input-date" name="inst_cal_date_5" type="date"/></td><td><input class="input-date" name="inst_due_date_5" type="date"/></td><td><input class="input" name="inst_checked_by_5"/></td></tr>
                <tr><td>6</td><td>Pressure gauge (hot water outlet line)</td><td><input class="input" name="inst_id_6"/></td><td><input class="input-date" name="inst_cal_date_6" type="date"/></td><td><input class="input-date" name="inst_due_date_6" type="date"/></td><td><input class="input" name="inst_checked_by_6"/></td></tr>
                <tr><td>7</td><td>Temperature gauge (chilled water inlet line)</td><td><input class="input" name="inst_id_7"/></td><td><input class="input-date" name="inst_cal_date_7" type="date"/></td><td><input class="input-date" name="inst_due_date_7" type="date"/></td><td><input class="input" name="inst_checked_by_7"/></td></tr>
                <tr><td>8</td><td>Temperature gauge (chilled water outlet line)</td><td><input class="input" name="inst_id_8"/></td><td><input class="input-date" name="inst_cal_date_8" type="date"/></td><td><input class="input-date" name="inst_due_date_8" type="date"/></td><td><input class="input" name="inst_checked_by_8"/></td></tr>
                <tr><td>9</td><td>Temperature gauge (hot water inlet line)</td><td><input class="input" name="inst_id_9"/></td><td><input class="input-date" name="inst_cal_date_9" type="date"/></td><td><input class="input-date" name="inst_due_date_9" type="date"/></td><td><input class="input" name="inst_checked_by_9"/></td></tr>
                <tr><td>10</td><td>Temperature gauge (hot water outlet line)</td><td><input class="input" name="inst_id_10"/></td><td><input class="input-date" name="inst_cal_date_10" type="date"/></td><td><input class="input-date" name="inst_due_date_10" type="date"/></td><td><input class="input" name="inst_checked_by_10"/></td></tr>
                <tr><td>11</td><td>Temperature / RH transmitter</td><td><input class="input" name="inst_id_11"/></td><td><input class="input-date" name="inst_cal_date_11" type="date"/></td><td><input class="input-date" name="inst_due_date_11" type="date"/></td><td><input class="input" name="inst_checked_by_11"/></td></tr>
                <tr><td>12</td><td>Differential pressure Transmitter</td><td><input class="input" name="inst_id_12"/></td><td><input class="input-date" name="inst_cal_date_12" type="date"/></td><td><input class="input-date" name="inst_due_date_12" type="date"/></td><td><input class="input" name="inst_checked_by_12"/></td></tr>
            </table>

            <h3>Dust Extractor</h3>
            <table>
                <tr><th>Sr. No.</th><th>Instrument Name</th><th>Instrument ID</th><th>Date of Calibration</th><th>Due Date</th><th>Checked By</th></tr>
                <tr><td>1</td><td>Differential Pressure Gauge (across Cartridge Filter)</td><td><input class="input" name="dust_inst_id_1"/></td><td><input class="input-date" name="dust_inst_cal_date_1" type="date"/></td><td><input class="input-date" name="dust_inst_due_date_1" type="date"/></td><td><input class="input" name="dust_inst_checked_by_1"/></td></tr>
                <tr><td>2</td><td>Differential Pressure Gauge (across fine filter)</td><td><input class="input" name="dust_inst_id_2"/></td><td><input class="input-date" name="dust_inst_cal_date_2" type="date"/></td><td><input class="input-date" name="dust_inst_due_date_2" type="date"/></td><td><input class="input" name="dust_inst_checked_by_2"/></td></tr>
                <tr><td>3</td><td>Differential Pressure Gauge (across HEPA filter)</td><td><input class="input" name="dust_inst_id_3"/></td><td><input class="input-date" name="dust_inst_cal_date_3" type="date"/></td><td><input class="input-date" name="dust_inst_due_date_3" type="date"/></td><td><input class="input" name="dust_inst_checked_by_3"/></td></tr>
            </table>

            <h3>Preventive Maintenance Details</h3>
            <table>
                <tr><th>Sr. No.</th><th>Equipment Name / ID</th><th>Done on</th><th>Due on</th><th>Checked by (Engineering) (Sign./Date)</th></tr>
                <tr><td>1</td><td><input class="input" name="pm_equipment_1"/></td><td><input class="input-date" name="pm_done_date_1" type="date"/></td><td><input class="input-date" name="pm_due_date_1" type="date"/></td><td><input class="input" name="pm_checked_by_1"/></td></tr>
                <tr><td>2</td><td><input class="input" name="pm_equipment_2"/></td><td><input class="input-date" name="pm_done_date_2" type="date"/></td><td><input class="input-date" name="pm_due_date_2" type="date"/></td><td><input class="input" name="pm_checked_by_2"/></td></tr>
            </table>

            <h3>Any other specific tests</h3>
            <ul>
                <li><input class="input" name="specific_test_1" value="Airflow volume measurement / ACPH"/></li>
                <li><input class="input" name="specific_test_2" value="HEPA filter Integrity test"/></li>
                <li><input class="input" name="specific_test_3" value="Non-Viable particulate count test"/></li>
                <li><input class="input" name="specific_test_4" value="Area Recovery test"/></li>
                <li><input class="input" name="specific_test_5" value="Airflow Pattern test"/></li>
                <li><input class="input" name="specific_test_6" value="Dust Extractor CFM measurement"/></li>
            </ul>

            <h3>Verification with the current P & ID</h3>
            <p><b>History card from date:</b> <input class="input-date" name="history_from_date_1" type="date"/> to <input class="input-date" name="history_to_date_1" type="date"/>.<br/>
            <b>Reviewed by (Engineering Department)</b> <input class="input" name="history_reviewed_by_1"/><br/>
            <b>Recommendation:</b> <input class="input" name="history_recommendation_1"/></p>

            <p><b>History card from date:</b> <input class="input-date" name="history_from_date_2" type="date"/> to <input class="input-date" name="history_to_date_2" type="date"/>.<br/>
            <b>Reviewed by (Engineering Department)</b> <input class="input" name="history_reviewed_by_2"/><br/>
            <b>Recommendation:</b> <input class="input" name="history_recommendation_2"/></p>

            <h3>History of Equipment / system</h3>
            <textarea name="equipment_history" rows="4" style="width:100%; border:1px solid #ddd; padding:8px;"></textarea>

            <h3>Review of Change Controls related to the Equipment / system</h3>
            <p>Review the Change Controls related to the Equipment / system under requalification and summarize;</p>

            <table>
                <tr><th>S. No.</th><th>Ref. Change Control No.</th><th>Summary of Changes</th></tr>
                <tr><td>1</td><td><input class="input" name="change_control_no_1"/></td><td><input class="input" name="change_summary_1"/></td></tr>
                <tr><td>2</td><td><input class="input" name="change_control_no_2"/></td><td><input class="input" name="change_summary_2"/></td></tr>
            </table>

            <p><b>Remarks / Recommendation (if any):</b> <textarea name="change_remarks" rows="3" style="width:100%; border:1px solid #ddd; padding:8px;"></textarea></p>

            <p><b>Reviewed By:</b> (Sign/Date) <input class="input" name="change_reviewed_by"/></p>

            <h5><strong>9.0 REQUALIFICATION CHECKS</strong></h5>
            <h3>Installation Re-qualification checks</h3>
            <table>
<table>
<tr>
<th>Checks</th>
<th>Acceptance criteria</th>
<th>Check</th>
<th>Observation</th>

<th>Done by (Engineering)</th>
</tr>

<!-- 1 -->
<tr>
<td>Electrical connections</td>

<td class="criteria"
data-text="All the electrical connection in the panel should be connected properly">
All the electrical connection in the panel should be connected properly
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="check_electrical_obs"/>
</td>
<td><input type="checkbox" onclick="engineeringCheck(this)">
<input class="input done-by" name="check_electrical_done_by"/>
</td>
</tr>


<!-- 2 -->
<tr>
<td>Check chilled water connections</td>

<td class="criteria"
data-text="Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: 2 to 8°C">
Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: 2 to 8°C
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="check_chilled_water_obs"/>
</td>
<td>
                          <input type="checkbox" onclick="engineeringCheck(this)">

<input class="input done-by" name="check_chilled_water_done_by"/>
</td>
</tr>


<!-- 3 -->
<tr>
<td>Check hot water connections</td>

<td class="criteria"
data-text="Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: ≥ 35.0°C">
Should be available. Inlet Pressure: > 1.0 KG/CM²; Inlet Temperature: ≥ 35.0°C
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="check_hot_water_obs"/>
</td>
<td><input type="checkbox" onclick="engineeringCheck(this)">
<input class="input done-by" name="check_hot_water_done_by"/>
</td>
</tr>


<!-- 4 -->
<tr>
<td>Leveling/ Centering/ Alignment</td>

<td class="criteria"
data-text="Should be properly leveled / centered / aligned">
Should be properly leveled / centered / aligned
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="check_leveling_obs"/>
</td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">

<input class="input done-by" name="check_leveling_done_by"/>
</td>
</tr>


<!-- 5 -->
<tr>
<td>Access and Clearance</td>

<td class="criteria"
data-text="Adequate space is available for accessing the equipment for operation, cleaning & maintenance">
Adequate space is available for accessing the equipment for operation, cleaning & maintenance
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="check_access_obs"/>
</td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">

<input class="input done-by" name="check_access_done_by"/>
</td>
</tr>

</table>


            <h3>General Checks: Verification of major components of equipment</h3>

<table>
<tr>
<th>Major components</th>
<th>Checks</th>
<th>Methodology</th>
<th>Acceptance criteria</th>
<th>Check</th>

<th>Observation/ Result</th>
<th>Done by</th>
</tr>

<!-- 1 -->
<tr>
<td>Machine frame and body part</td>
<td>Check any physical damage to the machine frame & body part.</td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No physical damage.">
No physical damage.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_frame_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
       <input class="input done-by" name="comp_frame_done_by"/></td>
</tr>


<!-- 2 -->
<tr>
<td>Fresh air filter</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Filter should not be damaged or choked">
Filter should not be damaged or choked
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_fresh_filter_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
   ><input class="input done-by" name="comp_fresh_filter_done_by"/></td>
</tr>


<!-- 3 -->
<tr>
<td>Pre filter</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Filter should not be damaged or choked">
Filter should not be damaged or choked
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_pre_filter_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
      <input class="input done-by" name="comp_pre_filter_done_by"/></td>
</tr>


<!-- 4 -->
<tr>
<td>Fine filter</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Filter should not be damaged or choked">
Filter should not be damaged or choked
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_fine_filter_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
       <input class="input done-by" name="comp_fine_filter_done_by"/></td>
</tr>


<!-- 5 -->
<tr>
<td>Bleed air filter</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Filter should not be damaged or choked">
Filter should not be damaged or choked
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_bleed_filter_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
   <input class="input done-by" name="comp_bleed_filter_done_by"/></td>
</tr>


<!-- 6 -->
<tr>
<td>Terminal HEPA filter</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Filter should not be damaged or choked">
Filter should not be damaged or choked
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_hepa_filter_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="comp_hepa_filter_done_by"/></td>
</tr>


<!-- 7 -->
<tr>
<td>Chilled water coil</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No leakages from coil tube and header.">
No leakages from coil tube and header.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_chilled_coil_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
  <input class="input done-by" name="comp_chilled_coil_done_by"/></td>
</tr>


<!-- 8 -->
<tr>
<td>Hot water coil</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No leakages from coil tube and header.">
No leakages from coil tube and header.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_hot_coil_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
    <input class="input done-by" name="comp_hot_coil_done_by"/></td>
</tr>


<!-- 9 -->
<tr>
<td>AHU Volume control damper</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Not damaged; smooth operation.">
Not damaged; smooth operation.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_damper_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
   <input class="input done-by" name="comp_damper_done_by"/></td>
</tr>


<!-- 10 -->
<tr>
<td>AHU drain tray and drain line</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Clean and clear.">
Clean and clear.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_drain_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
      <input class="input done-by" name="comp_drain_done_by"/></td>
</tr>


<!-- 11 -->
<tr>
<td>AHU supply and return Duct</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No damage/leakages; properly insulated.">
No damage/leakages; properly insulated.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_duct_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
   <input class="input done-by" name="comp_duct_done_by"/></td>
</tr>


<!-- 12 -->
<tr>
<td>Supply HEPA housing Box</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No physical damage; VCD smooth; grill fixed properly.">
No physical damage; VCD smooth; grill fixed properly.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_hepa_box_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
  <input class="input done-by" name="comp_hepa_box_done_by"/></td>
</tr>


<!-- 13 -->
<tr>
<td>VFD</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="Fixed properly in VFD panel.">
Fixed properly in VFD panel.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_vfd_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
    <input class="input done-by" name="comp_vfd_done_by"/></td>
</tr>


<!-- 14 -->
<tr>
<td>VFD / Electrical panel</td>
<td></td>
<td>Visual Inspection</td>

<td class="criteria" data-text="No damage; connections proper; cables dressed.">
No damage; connections proper; cables dressed.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="comp_electrical_panel_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="comp_electrical_panel_done_by"/></td>
</tr>

</table>


           <h3>Operational qualification checks: Functional Tests</h3>

<p>
In order to evaluate correct functioning / operation of the equipment,
checks such as start-up check, shut down check, operational checks
and safety checks shall be performed.
</p>

<table>
<tr>
<th>Sr. No</th>
<th>Identification/ Position of valve</th>
<th>Acceptance criteria</th>
<th>Check</th>
<th>Observation</th>
<th>Check</th>
<th>Done by (Engineering)</th>
</tr>


<!-- 1 -->
<tr>
<td>1</td>

<td>Supply air damper of room terminal boxes and main supply air duct</td>

<td class="criteria"
data-text="Open/close properly; locking arrangement available.">
Open/close properly; locking arrangement available.
</td>

<td>
<input type="checkbox" onclick="copyCriteria(this)">
</td>

<td>
<input class="input observation" name="op_supply_damper_obs"/>
</td>

<td>
<input class="input" name="op_supply_damper_done_by"/>
</td>
</tr>


<!-- 2 -->
<tr>
<td>2</td>

<td>
Return air damper of room terminal boxes / return riser ducts and main return air duct
</td>

<td class="criteria"
data-text="Open/close properly; locking arrangement available.">
Open/close properly; locking arrangement available.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_return_damper_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_return_damper_done_by"/></td>
</tr>


<!-- 3 -->
<tr>
<td>3</td>

<td>Fresh air & Exhaust air damper</td>

<td class="criteria"
data-text="Open/close properly; locking arrangement available.">
Open/close properly; locking arrangement available.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_fresh_damper_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_fresh_damper_done_by"/></td>
</tr>


<!-- 4 -->
<tr>
<td>4</td>

<td>Fire damper in main return air duct</td>

<td class="criteria"
data-text="Should be in open condition.">
Should be in open condition.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_fire_damper_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_fire_damper_done_by"/></td>
</tr>


<!-- 5 -->
<tr>
<td>5</td>

<td>Chilled water valve provided in chilled water manifold</td>

<td class="criteria"
data-text="Open/close smoothly.">
Open/close smoothly.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_chilled_valve_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_chilled_valve_done_by"/></td>
</tr>


<!-- 6 -->
<tr>
<td>6</td>

<td>Hot water valve provided in hot water manifold</td>

<td class="criteria"
data-text="Open/close smoothly.">
Open/close smoothly.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_hot_valve_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_hot_valve_done_by"/></td>
</tr>


<!-- 7 -->
<tr>
<td>7</td>

<td>Chilled water and hot water manifold leakages</td>

<td class="criteria"
data-text="No leakages from joints/fittings.">
No leakages from joints/fittings.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_manifold_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_manifold_done_by"/></td>
</tr>


<!-- 8 -->
<tr>
<td>8</td>

<td>AHU plenum doors position</td>

<td class="criteria"
data-text="Open/close properly for all sections.">
Open/close properly for all sections.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_plenum_doors_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_plenum_doors_done_by"/></td>
</tr>


<!-- 9 -->
<tr>
<td>9</td>

<td>Door gaskets</td>

<td class="criteria"
data-text="Provided in all plenum doors.">
Provided in all plenum doors.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_door_gaskets_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_door_gaskets_done_by"/></td>
</tr>


<!-- 10 -->
<tr>
<td>10</td>

<td>Filter fittings in the AHU</td>

<td class="criteria"
data-text="Fresh/pre/fine/bleed filters fixed properly; no side leakages.">
Fresh/pre/fine/bleed filters fixed properly; no side leakages.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_filter_fittings_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_filter_fittings_done_by"/></td>
</tr>


<!-- 11 -->
<tr>
<td>11</td>

<td>Supply grill fittings</td>

<td class="criteria"
data-text="Fixed properly from bottom side.">
Fixed properly from bottom side.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_supply_grill_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_supply_grill_done_by"/></td>
</tr>


<!-- 12 -->
<tr>
<td>12</td>

<td>Return grill fittings</td>

<td class="criteria"
data-text="Fixed properly from bottom side.">
Fixed properly from bottom side.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_return_grill_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_return_grill_done_by"/></td>
</tr>


<!-- 13 -->
<tr>
<td>13</td>

<td>Supply and return air duct and duct joints</td>

<td class="criteria"
data-text="Fixed properly; no leakages.">
Fixed properly; no leakages.
</td>

<td><input type="checkbox" onclick="copyCriteria(this)"></td>

<td><input class="input observation" name="op_duct_joints_obs"/></td>
 <td>
                          <input type="checkbox" onclick="engineeringCheck(this)">
                      </td>
<td><input class="input done-by" name="op_duct_joints_done_by"/></td>
</tr>

</table>


            <h3>Machine Performance</h3>
            <ol>
                <li>Switch ON the main power supply of AHU electrical panel: <input class="input" name="performance_1"/></li>
                <li>Manual / Auto Mode Select selector switch: <input class="input" name="performance_2"/></li>
                <li>Chilled water supply: <input class="input" name="performance_3"/></li>
                <li>Hot water supply: <input class="input" name="performance_4"/></li>
                <li>Auto start AHU: <input class="input" name="performance_5"/></li>
                <li>Stop AHU: <input class="input" name="performance_6"/></li>
                <li>AHU blower-motor function: <input class="input" name="performance_7"/></li>
                <li>DP gauge readings: <input class="input" name="performance_8"/></li>
                <li>Door safety interlock: <input class="input" name="performance_9"/></li>
                <li>Interlocking with Dust Extractor: <input class="input" name="performance_10"/></li>
            </ol>

            <h5><strong>10.0 DEVIATIONS (IF ANY)</strong></h5>
            <table>
                <tr><th>Deviation (If any)</th><th>Corrective Actions</th><th>Preventive Actions</th></tr>
                <tr><td><textarea name="deviation_1" rows="3" style="width:100%;"></textarea></td><td><textarea name="corrective_action_1" rows="3" style="width:100%;"></textarea></td><td><textarea name="preventive_action_1" rows="3" style="width:100%;"></textarea></td></tr>
            </table>

            <h5><strong>11.0 SUMMARY & CONCLUSION</strong></h5>
            <p>Based on thorough review of the available data, it is concluded that, the AHU ID. No. <span class="documentID">/ENG/AHU-005 </span> meets the Requalification criteria defined in the protocol. The AHU is in good condition and can be operate for regular use.</p>

            <p><b>Attachments</b></p>
            <ol>
                <li><input class="input" name="attachment_1" value="Airflow volume measurement / ACPH"/></li>
                <li><input class="input" name="attachment_2" value="HEPA filter Integrity test"/></li>
                <li><input class="input" name="attachment_3" value="Non-Viable particulate count test"/></li>
                <li><input class="input" name="attachment_4" value="Area Recovery test"/></li>
                <li><input class="input" name="attachment_5" value="Airflow Pattern test"/></li>
                <li><input class="input" name="attachment_6" value="Dust Extractor CFM measurement"/></li>
                <li><input class="input" name="attachment_7" value="Calibration certificates of instrument attached with AHU"/></li>
                <li><input class="input" name="attachment_8" value="Calibration certificates of instrument used during requalification of AHU"/></li>
                <li><input class="input" name="attachment_9" value="Training certificate of personnel involved from third party"/></li>
            </ol>

            <h5><strong>12.0 REFERENCES</strong></h5>
            <p>Installation & Operational qualification documents: <input class="input" name="reference_1" value="/IOQ/AHU/16-001"/><br/>
            Performance Qualification Document: <input class="input" name="reference_2" value="/PQR/AHU/16-001"/></p>

            <h5><strong>13.0 ABBREVIATIONS</strong></h5>
            <p>
                AHU - Air Handling Unit<br/>
                ACPH - Air Changes Per Hour<br/>
                CFM - Cubic Feet per minute<br/>
                DQ - Design Qualification<br/>
                cGMP - Current good Manufacturing practices<br/>
                MOC - Material of Construction<br/>
                PIBCV - Pressure Independent Balancing Control Valve<br/>
                PID - Proportional Integral Derivative<br/>
                URS - User Requirement Specification<br/>
                IQ - Installation Qualification<br/>
                OQ - Operational Qualification<br/>
                IOQ - Installation & Operational Qualification<br/>
                PQ - Performance Qualification<br/>
                PQR - Performance Qualification Report<br/>
                MM of WC - Millimeter of Water Column<br/>
                SS - Stainless Steel<br/>
                GI - Galvanized Iron<br/>
                VFD - Variable Frequency Drive<br/>
                KW - Kilo Watt<br/>
                P&ID - Piping & Instrumentation Diagram<br/>
                KG/CM2 - Kilogram per centimeter square<br/>
                °C - Degree Celsius
            </p>

            <h5><strong>14.0 REVISION HISTORY</strong></h5>
            <table>
                <tr><th>Revision No.</th><th>Effective Date</th><th>Reason for Revision</th></tr>
                <tr><td>00</td><td><input class="input-date" name="revision_date" type="date"/></td><td><input class="input" name="revision_reason" value="New Document"/></td></tr>
            </table>

            <h2>POST-APPROVAL SIGNATURE</h2>
            <p><b>PREPARED BY</b><br/>NAME & DESIGNATION<br/><input class="input" name="post_prepared_by_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_prepared_by_signature"/> <input class="input-date" name="post_prepared_by_date" type="date"/><br/>USER DEPARTMENT (ENGINEERING)</p>

            <p><b>CHECKED BY</b><br/>NAME & DESIGNATION<br/><input class="input" name="post_checked_by_engineering_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_checked_by_engineering_signature"/> <input class="input-date" name="post_checked_by_engineering_date" type="date"/><br/>ENGINEERING</p>

            <p><b>USER DEPARTMENT</b> (PRODUCTION)<br/>NAME & DESIGNATION<br/><input class="input" name="post_checked_by_production_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_checked_by_production_signature"/> <input class="input-date" name="post_checked_by_production_date" type="date"/></p>

            <p><b>QUALITY ASSURANCE</b><br/>NAME & DESIGNATION<br/><input class="input" name="post_quality_assurance_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_quality_assurance_signature"/> <input class="input-date" name="post_quality_assurance_date" type="date"/></p>

            <p><b>VALIDATION</b><br/>NAME & DESIGNATION<br/><input class="input" name="post_validation_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_validation_signature"/> <input class="input-date" name="post_validation_date" type="date"/></p>

            <p><b>APPROVED BY</b><br/>NAME & DESIGNATION<br/><input class="input" name="post_approved_by_name"/><br/>SIGNATURE & DATE<br/><input class="input" name="post_approved_by_signature"/> <input class="input-date" name="post_approved_by_date" type="date"/><br/>QUALITY ASSURANCE</p>

            <button type="submit" class="btn">Review Report</button>
        </form>
    </div>
</div>
    {{-- <p class="footnote">Note: Replace sample values with actual data. Use the toolbar to auto-fill today's date or to clear all fields. Printing hides the toolbar and form outlines.</p>  --}}


