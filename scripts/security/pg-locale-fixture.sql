\set ON_ERROR_STOP on
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE TEMPORARY TABLE ikoma_name_fixture (name text NOT NULL);
INSERT INTO ikoma_name_fixture(name) VALUES
('Ecole'),('École'),('école'),('ecole'),('Élodie'),('Elodie'),
('Amadou'),('amadou'),('Côte'),('cote'),('côte'),('São'),('Sao'),
('Niamey'),('Yamoussoukro'),('Abidjan'),('Aïcha'),('Aicha');
CREATE INDEX fixture_btree_idx ON ikoma_name_fixture(name);
CREATE INDEX fixture_trgm_idx ON ikoma_name_fixture USING gin(name gin_trgm_ops);
SELECT 'server_version|' || current_setting('server_version');
SELECT 'locale|' || datcollate || ';' || datctype FROM pg_database WHERE datname=current_database();
SELECT 'provider|' || datlocprovider::text FROM pg_database WHERE datname=current_database();
SELECT 'encoding|' || current_setting('server_encoding');
SELECT 'implicit_sort|' || string_agg(name,';' ORDER BY name COLLATE "C") FROM ikoma_name_fixture;
SELECT 'sort|' || string_agg(name,';' ORDER BY name COLLATE "C") FROM ikoma_name_fixture;
SELECT 'unaccent|' || string_agg(unaccent(name),';' ORDER BY name COLLATE "C") FROM ikoma_name_fixture;
SELECT 'search|' || COALESCE(string_agg(name,';' ORDER BY name),'') FROM ikoma_name_fixture WHERE lower(unaccent(name)) LIKE '%ecole%';
SELECT 'trigram|' || string_agg(name,';' ORDER BY similarity(name,'ecole') DESC,name COLLATE "C") FROM ikoma_name_fixture;
SELECT 'indexes|' || string_agg(indexname,';' ORDER BY indexname) FROM pg_indexes WHERE tablename='ikoma_name_fixture';
