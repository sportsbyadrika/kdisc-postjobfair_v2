CREATE DATABASE IF NOT EXISTS kdisc_postjobfair;
USE kdisc_postjobfair;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  role ENUM('administrator', 'crm_member', 'district_user') NOT NULL,
  mobile_number VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(150) DEFAULT NULL,
  address TEXT,
  password_hash VARCHAR(255) NOT NULL,
  active_status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  modified_by INT DEFAULT NULL,
  CONSTRAINT fk_users_modified_by FOREIGN KEY (modified_by) REFERENCES users(id) ON DELETE SET NULL
);


CREATE TABLE IF NOT EXISTS phone_directory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  designation VARCHAR(120) NOT NULL,
  team VARCHAR(120) NOT NULL,
  mobile_number VARCHAR(20) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  location VARCHAR(150) NOT NULL,
  active_status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  modified_by INT DEFAULT NULL,
  INDEX idx_phone_directory_team (team),
  INDEX idx_phone_directory_name (name),
  INDEX idx_phone_directory_mobile_number (mobile_number),
  INDEX idx_phone_directory_location (location),
  CONSTRAINT fk_phone_directory_modified_by FOREIGN KEY (modified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module_name ENUM('project', 'crm', 'report') NOT NULL,
  title VARCHAR(200) NOT NULL,
  details TEXT,
  status ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open',
  owner_user_id INT NOT NULL,
  active_status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  modified_by INT DEFAULT NULL,
  CONSTRAINT fk_activities_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT fk_activities_modified_by FOREIGN KEY (modified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS login_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  login_at DATETIME NOT NULL,
  logout_at DATETIME DEFAULT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  modified_by INT DEFAULT NULL,
  CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_login_logs_modified_by FOREIGN KEY (modified_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO users (name, role, mobile_number, email, address, password_hash, active_status, created_at, updated_at, modified_by)
SELECT 'System Admin', 'administrator', '9999999999', 'admin@example.com', 'HQ',
       '$2y$12$CZ/rfChWhCBmj34P91HWXOe6wQwsd1NzqpRjQUBu8MgRKbNP07heC', 1, NOW(), NOW(), NULL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE mobile_number = '9999999999');

CREATE TABLE IF NOT EXISTS job_fair_result (
  id INT AUTO_INCREMENT PRIMARY KEY,
  Job_Fair_No VARCHAR(255),
  Job_Fair_Date DATE,
  DWMS_ID VARCHAR(255),
  Candidate_Name VARCHAR(255),
  Employer_ID VARCHAR(255),
  Employer_Name VARCHAR(255),
  Job_Id VARCHAR(255),
  Job_Title_Name VARCHAR(255),
  Candidate_District VARCHAR(255),
  Candidate_Localbody VARCHAR(255),
  Candidate_Jobstation VARCHAR(255),
  Mobile_Number VARCHAR(255),
  EMail VARCHAR(255),
  SDPK VARCHAR(255),
  SDPK_District VARCHAR(255),
  Aggregator VARCHAR(255),
  Aggregator_SPOC_Name VARCHAR(255),
  Aggregator_SPOC_Mobile VARCHAR(255),
  Employer_SPOC_Name VARCHAR(255),
  Employer_SPOC_Mobile VARCHAR(255),
  CRM_Member VARCHAR(255),
  DSM_Member_1 VARCHAR(255),
  DSM_Member_2 VARCHAR(255),
  Category VARCHAR(255),
  Selection_Status VARCHAR(255),
  Data_uploaded_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  First_Call_Date DATETIME,
  First_Call_Done ENUM('Yes','No','Pending'),
  Offer_Letter_Generated ENUM('Yes','No','Pending'),
  Offer_Letter_Generated_Remarks VARCHAR(1000),
  Offer_Letter_Generated_Date DATETIME,
  Link_to_Offer_letter VARCHAR(1000),
  Link_to_Offer_letter_verified ENUM('Yes','No'),
  Confirm_Offer_Letter_Receipt_by_Candidate ENUM('Yes','No','Pending'),
  Confirm_letter_receipt_remarks VARCHAR(1000),
  confirmation_date DATETIME,
  response_from_employer VARCHAR(1000),
  Willing_to_Join ENUM('Yes','No'),
  willing_to_join_remarks VARCHAR(1000),
  Offer_Letter_Join_Date DATETIME,
  Challenge_Type VARCHAR(255),
  Challenge_to_be_addressed VARCHAR(255),
  Escalation_to_Aggregator_Due_Date DATETIME,
  Escalation_to_Aggregator_Date DATETIME,
  Escalation_to_Aggregator_Done ENUM('Yes','No','Pending'),
  DSM_Follow_Up_Date DATETIME,
  DSM_Follow_Up_Status ENUM('Yes','No','Pending'),
  Specific_Issues_Report_to_MS VARCHAR(255),
  MS_EscalationDate DATETIME,
  MS_Escalated ENUM('Yes','No','Not Applicable'),
  Candidate_Joined_Status ENUM('Yes','No','Pending','Future Date'),
  Candidate_Joined_Date DATETIME,
  Candidate_Joining_Future_Date DATE,
  Remarks_Candidate_Join VARCHAR(255),
  Candidate_Join_Remarks_Type VARCHAR(255),
  Shortlist_Prepratory_Call_Date DATETIME,
  Shortlist_Preparatory_Call_Status ENUM('Yes','No','Pending'),
  Shortlist_Next_Process VARCHAR(255),
  Shortlist_Number_of_Rounds VARCHAR(255),
  Shortlist_Process_Deadline_Date DATETIME,
  Shortlist_Current_Call_Status ENUM('Yes','No','Pending'),
  Shortlist_Current_Process_Status ENUM('Completed','Pending','Review in progress'),
  Shortlist_Candidate_Status ENUM('Shortlisted','Selected','Rejected','Onhold','Review in progress','Selected for next round','Yet to be contacted'),
  Shortlist_remarks VARCHAR(1000)
);


CREATE TABLE IF NOT EXISTS aggregator_offer_letter_upload (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_fair_id VARCHAR(255),
  job_fair_date DATETIME,
  sl_no VARCHAR(100),
  dwms_id VARCHAR(255),
  candidate_name VARCHAR(255),
  job_id VARCHAR(255),
  job_role VARCHAR(255),
  date_of_issuing_offer_letter DATETIME,
  offer_letter_generated VARCHAR(20),
  offer_letter_given_to_candidate VARCHAR(20),
  link_to_offer_letter VARCHAR(1000),
  offer_letter_generated_date DATETIME,
  employer_id VARCHAR(255),
  employer_name VARCHAR(255),
  aggregator VARCHAR(255),
  offer_letter_status VARCHAR(255),
  aggregator_remarks_ictak TEXT,
  status VARCHAR(255),
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidate_call_purpose (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purpose_name VARCHAR(255) NOT NULL UNIQUE,
  active_status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidate_call_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  stage ENUM('Employer Connect','Candidate Connect','Aggregator Contact') NOT NULL,
  call_name VARCHAR(255),
  call_mobile VARCHAR(255),
  purpose_id INT DEFAULT NULL,
  call_datetime DATETIME NOT NULL,
  call_status ENUM('Attended','Not attended','Invalid number') NOT NULL,
  call_remarks TEXT,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_candidate_call_history_candidate_id (candidate_id),
  INDEX idx_candidate_call_history_purpose_id (purpose_id),
  INDEX idx_candidate_call_history_created_by (created_by),
  CONSTRAINT fk_candidate_call_history_candidate
    FOREIGN KEY (candidate_id) REFERENCES job_fair_result(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_candidate_call_history_purpose
    FOREIGN KEY (purpose_id) REFERENCES candidate_call_purpose(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_candidate_call_history_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS candidate_shortlist_rounds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  round_number INT NOT NULL,
  round_scheduled_date DATE NOT NULL,
  round_type ENUM('Interview','Test','Other') NOT NULL,
  round_status ENUM('Pending at Employer','Pending at Candidate','Ongoing','Completed') NOT NULL,
  round_remarks ENUM('Not Scheduled','Candidate not informed','Candidate not interested','Not applicable') DEFAULT NULL,
  round_selection_status ENUM('Selected','Rejected','Pending','Ongoing','Candidate not Attended','Candidate Not Willing','Selected for next round') NOT NULL,
  additional_remarks TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  updated_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_candidate_shortlist_rounds_candidate_id (candidate_id),
  INDEX idx_candidate_shortlist_rounds_created_by (created_by),
  INDEX idx_candidate_shortlist_rounds_updated_by (updated_by),
  CONSTRAINT fk_candidate_shortlist_rounds_candidate
    FOREIGN KEY (candidate_id) REFERENCES job_fair_result(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_candidate_shortlist_rounds_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_candidate_shortlist_rounds_updated_by
    FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
);


INSERT INTO candidate_call_purpose (purpose_name)
VALUES
  ('Follow-up'),
  ('Document Collection'),
  ('Offer Confirmation'),
  ('Joining Coordination')
ON DUPLICATE KEY UPDATE purpose_name = VALUES(purpose_name);
