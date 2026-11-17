# Complaint handling SOP

## 1. Purpose and scope

Use this procedure to record, review, assign, and track farmer complaints in the Farmer Portal. A dealer enters the farmer's details and complaint; the portal does not currently provide a separate farmer login role.

## 2. Roles

- **Dealer:** Enters a complaint with farmer, location, product, complaint type, and supporting details or files.
- **Area Manager:** Reviews dealer-submitted complaints for districts assigned to their account and approves or rejects them.
- **Department:** Receives approved complaints routed to its department and works on the assigned issue.
- **Administrator:** Manages users and complaint records, can update statuses and assignments, and reviews reports and audit history.

## 3. Complaint process

1. Sign in and select **New complaint**.
2. Enter the farmer and complaint information. Select the correct district and complaint type, describe the issue, and attach relevant evidence.
3. Submit the complaint. The portal creates a reference and starts an approval request when an active Area Manager is assigned to that district.
4. The dealer-submitted complaint remains **Pending** while awaiting review. Its department assignment and department notifications are held until approval.
5. The Area Manager opens **Approvals** from the dashboard or follows the approval notification. Review the complaint details and then:
   - **Approve:** The configured department(s) are assigned and notified. The response SLA starts from approval.
   - **Reject:** The complaint status becomes **Rejected**, and the submitter is notified.
6. If no active Area Manager is assigned to the complaint's district, the portal follows the existing direct department-routing process.
7. Administrators can update complaint status and assignments using the complaint edit or bulk-action pages. Available statuses include **Pending**, **In Progress**, **Resolved**, **Closed**, and **Rejected**.
8. Use dashboard search and filters to find complaints. Use **Complaint analysis** for category and product summaries, and use the export actions when a report is needed.

Complaint attachments, including images, videos, and documents, are stored in a private folder named for the complaint ID under `storage/complaints`. Draft attachments stay in private storage until the complaint is submitted, then are copied into the complaint's folder.

Administrators can maintain State, District, Taluka, and Village suggestions from **Settings → Location options**. Complaint forms offer those names as dropdown suggestions and also allow typing; names are matched case-insensitively within their parent location so an existing spelling is reused.

Enter complaint locations in this order: **State → District → Taluka → Village**. Selecting a dealer fills its saved location; Area Managers start with their first assigned district and its state when that state is uniquely known.

To share one complaint for management, supplier, or department review/approval, open its details on the dashboard and select **Print for review / approval**. Choose the audience and purpose, optionally enter the recipient or supplier name, then select **Print / Save as PDF**. Image attachments appear on the sheet; download other files separately. The existing **Department print / PDF** action remains available for department material history.

For departmental evidence, open the assigned complaint's **Sample & faulty material records** page. QC users can upload testing reports, Store and Dispatch users can upload material supply proof, and other assigned departments can add department evidence. Upload images, videos, or documents; the portal records the uploader and time, keeps the files under that complaint, and includes them on the department and review print sheets. Administrators can upload any of these evidence types.

To bulk update location suggestions, open **Settings → Locations → Download template**, fill the first worksheet with the four required columns (**State**, **District**, **Taluka**, **Village**), then choose the `.xlsx` file and select **Import locations**. Each row must contain the complete location path. The import supports up to 10,000 rows and 5 MB; if any row is invalid, fix the listed row(s) and upload again. Existing matching locations are reused, not duplicated. This updates the dropdown catalog only; it does not change location details already saved on existing complaints or dealers.

The approval gate applies to new dealer submissions after this feature is installed. Existing complaints are not moved into the approval queue.

## 4. Department routing

The configured routing is:

| Complaint type | Department(s) |
| --- | --- |
| Short Supply | Dispatch, Store |
| Material Fault | QC |
| Wrong Dispatch | Dispatch, Store |
| Exceed Supply | Dispatch, Store |
| Other | Admin |

## 5. Notifications

Unread notifications appear in the dashboard header beside **Notifications**. Approval notifications link Area Managers to the approval queue. After approval, assigned departments and administrators receive assignment notifications; after rejection, the submitter receives a decision notification.

## 6. Testing from another PC on the same LAN/Wi-Fi

1. On the host PC, start **Apache** and **MySQL** in Laragon. Keep the project under `C:\laragon\www\farmer-portal`.
2. Confirm the app works on the host at `http://localhost/farmer-portal/`.
3. On the host, run `ipconfig` and note the active Wi-Fi or Ethernet **IPv4 Address** (for example, `192.168.1.25`).
4. Connect the second PC to the same private LAN/Wi-Fi and open `http://<host-ipv4>/farmer-portal/` (for example, `http://192.168.1.25/farmer-portal/`).
5. If the page does not load, allow Apache's HTTP port (usually TCP 80) through Windows Defender Firewall for **Private** networks only, and confirm Apache accepts connections on the LAN interface.
6. Sign in with a test account. The application and database run on the host PC; the second PC only needs a browser. Do not expose MySQL port 3306 to the LAN.
7. Use this only on a trusted private network. Do not enable router port forwarding or expose the development server to the internet. The current local database configuration uses the MySQL `root` account without a password; configure a protected database account before broader or untrusted-network use.


Host PC પર Laragonમાં Apache અને MySQL ચાલુ કરો, ipconfigથી hostનું IPv4 જુઓ, પછી એ જ Wi‑Fi/LAN પરના બીજા PCમાં http://<host-ipv4>/farmer-portal/ ખોલો. જરૂર પડે તો Windows Firewallમાં Apacheનો HTTP port (સામાન્ય રીતે 80) ફક્ત Private network માટે મંજૂર કરો. MySQL port 3306 ખોલશો નહીં; હાલનું root વગર passwordનું database configuration ફક્ત વિશ્વસનીય local testing માટે વાપરો