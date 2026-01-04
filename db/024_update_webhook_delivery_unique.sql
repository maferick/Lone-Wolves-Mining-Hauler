ALTER TABLE webhook_delivery
  DROP INDEX uq_delivery_transition,
  ADD UNIQUE KEY uq_delivery_transition (webhook_id, event_key, entity_type, entity_id, transition_to);
