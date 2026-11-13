-- EcoLink demo data (OPTIONAL). Import AFTER schema.sql.
-- All demo accounts use the password:  Password123
SET NAMES utf8mb4;
INSERT IGNORE INTO users (user_id, full_name, email, phone, password_hash, role, organization_name, address, pickup_area, is_verified, verification_status, email_verified, capacity_score) VALUES
 (1,'EcoLink Admin','admin@ecolink.test','01700000000','$2y$10$h/8/VnqUpqGPz1Pr53pTse4VpH6d4Y3mTkURorBpC/4VV5u4dwQS6','admin',NULL,'Dhaka','Dhaka',1,'verified',1,5),
 (2,'Karim Hasan','donor1@ecolink.test','01711111111','$2y$10$h/8/VnqUpqGPz1Pr53pTse4VpH6d4Y3mTkURorBpC/4VV5u4dwQS6','donor',NULL,'Road 5, Uttara, Dhaka','Uttara, Dhaka',1,'verified',1,5),
 (3,'Nusrat Jahan','donor2@ecolink.test','01722222222','$2y$10$h/8/VnqUpqGPz1Pr53pTse4VpH6d4Y3mTkURorBpC/4VV5u4dwQS6','donor',NULL,'Sector 4, Uttara, Dhaka','Uttara, Dhaka',1,'verified',1,5),
 (4,'Sumon Rahman','receiver1@ecolink.test','01733333333','$2y$10$h/8/VnqUpqGPz1Pr53pTse4VpH6d4Y3mTkURorBpC/4VV5u4dwQS6','receiver','Hope Learning Center','Uttara, Dhaka','Uttara, Dhaka',1,'verified',1,7),
 (5,'Farhana Akter','receiver2@ecolink.test','01744444444','$2y$10$h/8/VnqUpqGPz1Pr53pTse4VpH6d4Y3mTkURorBpC/4VV5u4dwQS6','receiver','Light Orphanage','Mirpur, Dhaka','Mirpur, Dhaka',1,'verified',1,8);

INSERT IGNORE INTO delivery_hubs (hub_id, name, area, address, phone, open_hours) VALUES
 (1,'Uttara Community Hub','Uttara, Dhaka','House 12, Sector 7, Uttara','01755555555','10 AM - 8 PM, Sat-Thu'),
 (2,'Mirpur Community Hub','Mirpur, Dhaka','Block C, Section 10, Mirpur','01766666666','10 AM - 8 PM, Sat-Thu');

INSERT IGNORE INTO donations (donation_id, donor_id, category_id, title, description, quantity, unit, condition_grade, pickup_address, status, approved_by, approved_at) VALUES
 (1,2,2,'Winter jackets','Clean, ready to wear',15,'pcs','good','Road 5, Uttara, Dhaka','available',1,NOW()),
 (2,3,3,'School textbooks (Class 6-8)','Bangla + English medium',40,'pcs','good','Sector 4, Uttara, Dhaka','available',1,NOW()),
 (3,2,1,'Packed dry food','Biscuits and noodles',30,'packs','new','Road 5, Uttara, Dhaka','pending_approval',NULL,NULL);
INSERT IGNORE INTO lifecycle_log (donation_id, status, note, changed_by) VALUES
 (1,'available','Approved by admin',1),(2,'available','Approved by admin',1),(3,'pending_approval','Submitted by donor, waiting for admin approval',2);
INSERT IGNORE INTO demand_profiles (receiver_id, category_id, needed_quantity, priority_level) VALUES (4,3,50,'high'),(5,2,20,'critical');
