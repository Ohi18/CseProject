# Booking Feature Setup Instructions

## Database Migration

Before using the booking feature, you need to add the `service_id` column to the `slots` table.

### Option 1: Using phpMyAdmin
1. Open phpMyAdmin
2. Select the `goglam` database
3. Go to the SQL tab
4. Run the SQL from `add_service_id_to_slots.sql`:

```sql
ALTER TABLE `slots` 
ADD COLUMN `service_id` INT(11) NULL AFTER `saloon_id`,
ADD KEY `fk_slots_service` (`service_id`);

ALTER TABLE `slots`
ADD CONSTRAINT `fk_slots_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL ON UPDATE CASCADE;
```

### Option 2: Using Command Line
```bash
mysql -u root -p goglam < add_service_id_to_slots.sql
```

### Option 3: Manual Execution
You can copy and paste the SQL commands from `add_service_id_to_slots.sql` into your MySQL client.

## Features Implemented

1. **Service Selection**: Customers can click "Select" button on any service in the Services tab
2. **Slot Selection Modal**: 
   - Shows next 7 days for date selection
   - Hourly time slots from 9 AM to 6 PM
   - Automatically disables already booked slots
   - Disables past dates and times
3. **Booking Confirmation**: 
   - Shows booking summary before confirmation
   - Displays service name, date, time, and price
   - Confirms booking to database
4. **Success Notification**: 
   - Shows success message after booking
   - Auto-dismisses after 5 seconds
   - Redirects to prevent form resubmission

## Database Tables Used

- `confirmation`: Stores booking confirmation with total_amount, slot_time, customer_id
- `slots`: Stores slot bookings with saloon_id, customer_id, confirmation_id, service_id, status
- `services`: Contains service information used for booking

## Notes

- The booking system generates available slots on-the-fly (not pre-populated in database)
- Slots are checked against existing bookings to prevent double-booking
- Booking time format: YYYY-MM-DD HH:MM:SS
- Default business hours: 9:00 AM - 6:00 PM (can be customized in JavaScript)





