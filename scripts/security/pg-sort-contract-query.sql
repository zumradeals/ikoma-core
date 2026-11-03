\set ON_ERROR_STOP on
SELECT jsonb_build_object(
  'pg_major',split_part(current_setting('server_version'),'.',1),
  'encoding',current_setting('server_encoding'),
  'collation',(SELECT datcollate FROM pg_database WHERE datname=current_database()),
  'collation_provider',(SELECT datlocprovider::text FROM pg_database WHERE datname=current_database()),
  'count',(SELECT count(*) FROM ikoma_sort_fixture),
  'implicit_order',(SELECT jsonb_agg(id::text ORDER BY full_name,id) FROM ikoma_sort_fixture),
  'explicit_order',(SELECT jsonb_agg(id::text ORDER BY full_name COLLATE "C",id) FROM ikoma_sort_fixture),
  'normalized_explicit_order',(SELECT jsonb_agg(id::text ORDER BY public.ikoma_normalize(full_name) COLLATE "C",full_name COLLATE "C",id) FROM ikoma_sort_fixture),
  'priority_tiebreak_order',(SELECT jsonb_agg(id::text ORDER BY coarse_score DESC,priority_circuit DESC,fine_score DESC,full_name COLLATE "C",id) FROM ikoma_sort_fixture),
  'normalized_names',(SELECT jsonb_object_agg(id::text,public.ikoma_normalize(full_name)) FROM ikoma_sort_fixture),
  'search_ecole',(SELECT jsonb_agg(id::text ORDER BY full_name COLLATE "C",id) FROM ikoma_sort_fixture WHERE public.ikoma_normalize(full_name) LIKE '%ecole%'),
  'search_kone',(SELECT jsonb_agg(id::text ORDER BY full_name COLLATE "C",id) FROM ikoma_sort_fixture WHERE public.ikoma_normalize(full_name) LIKE '%kone%'),
  'index_names',(SELECT jsonb_agg(indexname ORDER BY indexname) FROM pg_indexes WHERE schemaname='public' AND tablename='ikoma_sort_fixture')
)::text;
