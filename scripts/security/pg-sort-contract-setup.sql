\set ON_ERROR_STOP on
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE OR REPLACE FUNCTION public.ikoma_normalize(text)
RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, $1)) $$;
CREATE TABLE ikoma_sort_fixture(
  id uuid PRIMARY KEY,
  full_name text NOT NULL,
  priority_circuit boolean NOT NULL,
  coarse_score numeric(3,1) NOT NULL,
  fine_score numeric(4,3) NOT NULL
);
INSERT INTO ikoma_sort_fixture(id,full_name,priority_circuit,coarse_score,fine_score)
SELECT ('00000000-0000-4000-8000-' || lpad(n::text, 12, '0'))::uuid,
       full_name, vip, coarse, fine
FROM (VALUES
 (1,'Ecole',false,0.8,0.844),
 (2,'École',false,0.8,0.844),
 (3,'école',true,0.8,0.844),
 (4,'ecole',false,0.8,0.844),
 (5,'Élodie',false,0.5,0.524),
 (6,'Elodie',false,0.5,0.524),
 (7,'Amadou',false,0.4,0.421),
 (8,'amadou',false,0.4,0.421),
 (9,'Côte',false,0.6,0.627),
 (10,'cote',false,0.6,0.627),
 (11,'côte',false,0.6,0.627),
 (12,'São',false,0.5,0.500),
 (13,'Sao',false,0.5,0.500),
 (14,'Niamey',false,0.3,0.350),
 (15,'Yamoussoukro',false,0.3,0.350),
 (16,'Abidjan',false,0.3,0.350),
 (17,'Aïcha',false,0.7,0.790),
 (18,'Aicha',false,0.7,0.790),
 (19,'Marie Koné',false,0.9,0.900),
 (20,'Koné Mariam',true,0.9,0.900),
 (21,'Marie Koné',false,0.9,0.900),
 (22,'Jean Kone',false,0.9,0.900)
) AS x(n,full_name,vip,coarse,fine);
CREATE INDEX ikoma_sort_fixture_name_idx ON ikoma_sort_fixture(full_name);
CREATE INDEX ikoma_sort_fixture_name_trgm_idx ON ikoma_sort_fixture USING gin(public.ikoma_normalize(full_name) gin_trgm_ops);
