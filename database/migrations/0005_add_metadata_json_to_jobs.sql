-- Add metadata_json column to store structured placement hints
ALTER TABLE jobs ADD COLUMN metadata_json TEXT NULL;
