\set ON_ERROR_STOP on
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE TABLE s03_visiteurs_fictifs (
    id integer PRIMARY KEY,
    full_name text NOT NULL,
    event_code text NOT NULL DEFAULT 'EVENEMENT_FICTIF'
);
INSERT INTO s03_visiteurs_fictifs (id,full_name) VALUES
(1,'École'),(2,'Ecole'),(3,'école'),(4,'ecole'),(5,'Aïcha'),
(6,'Aicha'),(7,'Côte'),(8,'cote'),(9,'Élodie'),(10,'Elodie'),
(11,'Amadou'),(12,'amadou'),(13,'São'),(14,'Sao'),(15,'Abidjan'),
(16,'Yamoussoukro'),(17,'Moussa'),(18,'Moussa'),(19,'Niamey'),
(20,'CÔTE'),(21,'Sira'),(22,'Sira');
CREATE INDEX s03_visiteurs_ordre_c_idx
    ON s03_visiteurs_fictifs (full_name COLLATE "C", id);
CREATE INDEX s03_visiteurs_recherche_idx
    ON s03_visiteurs_fictifs USING gin (full_name gin_trgm_ops);
