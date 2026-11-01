<p class="lead">Create your organization or link an existing one. You can use it immediately, with no admin approval.</p>
<div class="dashboard-grid"><article class="panel"><h2>Create an organization</h2><form data-api="/api/organizations/save">
<?php field('name','Organization name','text','','required maxlength="180"');field('address','Service address','textarea','','required'); ?>
<div class="field-pair"><?php field('latitude','Latitude','number','','required min="-90" max="90" step="any"');field('longitude','Longitude','number','','required min="-180" max="180" step="any"'); ?></div>
<button type="button" class="quiet-button" data-location>Use my current location</button>
<?php field('contact_phone','Contact phone','tel');field('registration_number','Registration / license reference (optional)');formend('Create and link organization'); ?>
</article><article class="panel"><h2>Link an existing organization</h2><p>Organizations listed here accept self-service linking for your account type. Each person manages their own listings or receiving capacity.</p>
<?php if ($data['directory']): ?><form data-api="/api/organizations/join"><?php choice('organization_id','Organization',array_column($data['directory'],'name','id'));formend('Link organization'); ?><?php else: ?><p class="empty-state">No organizations of your type yet. Create the first one.</p><?php endif ?>
</article></div>
<h2 class="section-title">Your linked organizations</h2>
<?php if (!$data['organizations']): ?><p class="empty-state">You have no linked organizations.</p><?php endif ?>
<?php foreach ($data['organizations'] as $org): ?><article class="panel spaced"><h2><?= e($org['name']) ?></h2><p><?= e($org['address']) ?> · <?= e($org['type']) ?></p>
<div class="map" data-lat="<?= e($org['latitude']) ?>" data-lng="<?= e($org['longitude']) ?>" aria-label="Organization location"></div>
<?php if ((int)$org['owner_user_id']===(int)$user['id']): ?><details><summary>Edit organization</summary><form data-api="/api/organizations/save"><input type="hidden" name="id" value="<?= e($org['id']) ?>"><?php field('name','Name','text',$org['name'],'required');field('address','Address','textarea',$org['address'],'required');field('latitude','Latitude','number',$org['latitude'],'required step="any" min="-90" max="90"');field('longitude','Longitude','number',$org['longitude'],'required step="any" min="-180" max="180"');field('contact_phone','Phone','tel',$org['contact_phone']);field('registration_number','Registration reference','text',$org['registration_number']);formend(); ?></details>
<details><summary>Add optional supporting document</summary><form data-api="/api/uploads" enctype="multipart/form-data"><input type="hidden" name="kind" value="document"><input type="hidden" name="id" value="<?= e($org['id']) ?>"><?php field('file','Document (PDF or image, up to 5 MB)','file','','required accept="application/pdf,image/jpeg,image/png,image/webp"');formend('Upload document'); ?></details><?php endif ?>
<?php foreach ($data['documents'] as $document): if ((int)$document['organization_id']===(int)$org['id']): ?><p><a class="underlined" href="/files/document/<?= e($document['id']) ?>" target="_blank" rel="noopener"><?= e($document['original_name']) ?></a></p><?php endif;endforeach ?>
</article><?php endforeach ?>
