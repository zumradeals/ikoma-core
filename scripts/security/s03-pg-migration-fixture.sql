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

-- Données entièrement fictives et déterministes : contraintes et charges relationnelles.
-- Elles complètent les accents de l'échantillon initial, sans représenter une base client.
INSERT INTO s03_visiteurs_fictifs (id, full_name)
SELECT n, 'Visiteur fictif ' || lpad(n::text, 5, '0')
FROM generate_series(23, 1000) AS seq(n);
CREATE TABLE s03_badges_fictifs (
    id integer PRIMARY KEY,
    visitor_id integer NOT NULL REFERENCES s03_visiteurs_fictifs (id),
    badge_code text NOT NULL UNIQUE,
    status text NOT NULL CHECK (status IN ('disponible', 'associe', 'restitue'))
);
INSERT INTO s03_badges_fictifs (id, visitor_id, badge_code, status)
SELECT id, id, 'BADGE-FICTIF-' || lpad(id::text, 5, '0'), 'associe'
FROM s03_visiteurs_fictifs;
CREATE INDEX s03_badges_visiteur_idx ON s03_badges_fictifs (visitor_id);
CREATE TABLE s03_passages_fictifs (
    id integer PRIMARY KEY,
    badge_id integer NOT NULL REFERENCES s03_badges_fictifs (id),
    direction text NOT NULL CHECK (direction IN ('entree', 'sortie')),
    recorded_at timestamptz NOT NULL
);
INSERT INTO s03_passages_fictifs (id, badge_id, direction, recorded_at)
SELECT n, ((n - 1) % 1000) + 1,
       CASE WHEN n % 2 = 1 THEN 'entree' ELSE 'sortie' END,
       timestamptz '2026-01-01 00:00:00+00' + n * interval '1 second'
FROM generate_series(1, 2000) AS seq(n);
CREATE INDEX s03_passages_badge_time_idx
    ON s03_passages_fictifs (badge_id, recorded_at);
