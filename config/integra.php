<?php
return ['files_driver'=>env('INTEGRA_FILES_DRIVER','local'),'supabase_url'=>env('SUPABASE_URL'),'supabase_key'=>env('SUPABASE_SECRET_KEY'),'bucket'=>env('SUPABASE_BUCKET','integra-private')];
