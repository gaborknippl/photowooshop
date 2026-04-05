jQuery(document).ready(function ($) {
    const workspace = $('#tpl-workspace');
    const bgInput = $('#_photowooshop_bg_url');
    const slotsInput = $('#_photowooshop_slots_json');
    const addBtn = $('#add-slot-btn');
    const layerListContainer = $('#tpl-layer-list');
    const layerPanel = $('#tpl-layer-panel');
    const imageSettingsContainer = $('#image-slots-container');

    let slots = [];
    let textSlots = [];
    let shapeSlots = [];
    let expandedLayerKey = null;

    const textSlotsInput = $('#_photowooshop_text_slots_json');
    const addTextBtn = $('#add-text-btn');
    const textSettingsContainer = $('#text-slots-container');
    const shapeSlotsInput = $('#_photowooshop_shape_slots_json');
    const addShapeBtn = $('#add-shape-btn');
    const shapeSettingsContainer = $('#shape-slots-container');

    try {
        slots = JSON.parse(slotsInput.val() || '[]');
    } catch (e) { slots = []; }

    try {
        textSlots = JSON.parse(photowooshop_admin_vars.text_slots || '[]');
    } catch (e) { textSlots = []; }

    try {
        shapeSlots = JSON.parse(photowooshop_admin_vars.shape_slots || '[]');
    } catch (e) { shapeSlots = []; }

    function init() {
        initializeLayerPanelUi();
        if (photowooshop_admin_vars.bg_url) {
            updateBackground(photowooshop_admin_vars.bg_url);
        }
        renderSlots();
        renderTextSlots();
        renderShapeSlots();
        renderLayerManager();
    }

    function initializeLayerPanelUi() {
        // Legacy panels were split by type and became hard to follow. Keep data flow,
        // but move editing to one expandable layer accordion.
        imageSettingsContainer.closest('.tpl-settings').hide();
        textSettingsContainer.closest('.tpl-settings').hide();
        shapeSettingsContainer.closest('.tpl-settings').hide();

        if (!layerPanel.find('.layer-quick-actions').length) {
            const controls = `
                <div class="layer-quick-actions" style="padding:8px 12px; border-bottom:1px solid #eee; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="button button-small" data-layer-add="image">+ Képhely</button>
                    <button type="button" class="button button-small" data-layer-add="text">+ Szöveg</button>
                    <button type="button" class="button button-small" data-layer-add="shape">+ Alakzat</button>
                </div>
            `;
            layerPanel.find('#tpl-layer-list').before(controls);
        }
    }

    function saveTextSlots() {
        textSlotsInput.val(JSON.stringify(textSlots));
    }

    function saveShapeSlots() {
        shapeSlotsInput.val(JSON.stringify(shapeSlots));
    }

    function defaultImageName(index) {
        return `Kephely ${index + 1}`;
    }

    function defaultTextName(index) {
        return `Szoveg ${index + 1}`;
    }

    function defaultShapeName(index) {
        return `Alakzat ${index + 1}`;
    }

    function getImageSlotCornerRadii(slot, fallback = 10) {
        const base = typeof slot.radius === 'number' ? Math.max(0, slot.radius) : fallback;
        const source = (slot && slot.radii && typeof slot.radii === 'object') ? slot.radii : {};

        const tl = Math.max(0, parseFloat(source.tl));
        const tr = Math.max(0, parseFloat(source.tr));
        const br = Math.max(0, parseFloat(source.br));
        const bl = Math.max(0, parseFloat(source.bl));

        return {
            tl: Number.isNaN(tl) ? base : tl,
            tr: Number.isNaN(tr) ? base : tr,
            br: Number.isNaN(br) ? base : br,
            bl: Number.isNaN(bl) ? base : bl
        };
    }

    function getUnifiedRadiusValue(radii) {
        if (radii.tl === radii.tr && radii.tr === radii.br && radii.br === radii.bl) {
            return radii.tl;
        }
        return '';
    }

    function getImageSlotBorderRadiusCss(slot, fallback = 10) {
        const radii = getImageSlotCornerRadii(slot, fallback);
        return `${radii.tl}px ${radii.tr}px ${radii.br}px ${radii.bl}px`;
    }

    function getShapeCornerRadii(slot, fallback = 10) {
        const base = typeof slot.radius === 'number' ? Math.max(0, slot.radius) : fallback;
        const source = (slot && slot.radii && typeof slot.radii === 'object') ? slot.radii : {};

        const tl = Math.max(0, parseFloat(source.tl));
        const tr = Math.max(0, parseFloat(source.tr));
        const br = Math.max(0, parseFloat(source.br));
        const bl = Math.max(0, parseFloat(source.bl));

        return {
            tl: Number.isNaN(tl) ? base : tl,
            tr: Number.isNaN(tr) ? base : tr,
            br: Number.isNaN(br) ? base : br,
            bl: Number.isNaN(bl) ? base : bl
        };
    }

    function getShapeBorderRadiusCss(slot, fallback = 10) {
        const radii = getShapeCornerRadii(slot, fallback);
        return `${radii.tl}px ${radii.tr}px ${radii.br}px ${radii.bl}px`;
    }

    function updateCornerMapPreview(row, radii) {
        const preview = row.find('.image-corner-map-preview');
        if (!preview.length) {
            return;
        }

        preview.css('border-radius', `${radii.tl}px ${radii.tr}px ${radii.br}px ${radii.bl}px`);
        preview.find('[data-corner="tl"]').text(`TL ${Math.round(radii.tl)}`);
        preview.find('[data-corner="tr"]').text(`TR ${Math.round(radii.tr)}`);
        preview.find('[data-corner="br"]').text(`BR ${Math.round(radii.br)}`);
        preview.find('[data-corner="bl"]').text(`BL ${Math.round(radii.bl)}`);
    }

    function ensureSlotDefaults() {
        slots.forEach((slot, index) => {
            if (typeof slot.z !== 'number') slot.z = 10;
            if (typeof slot.radius !== 'number') slot.radius = 10;
            slot.radii = getImageSlotCornerRadii(slot, 10);
            if (!slot.name) slot.name = defaultImageName(index);
        });

        textSlots.forEach((slot, index) => {
            if (typeof slot.z !== 'number') slot.z = 50;
            if (!slot.name) slot.name = defaultTextName(index);
        });

        shapeSlots.forEach((slot, index) => {
            if (typeof slot.z !== 'number') slot.z = 30;
            if (!slot.name) slot.name = defaultShapeName(index);
        });
    }

    function getLayerItemsSorted() {
        const items = [];

        slots.forEach((slot, index) => {
            items.push({ key: `image-${index}`, type: 'image', index: index, name: slot.name, z: slot.z });
        });

        textSlots.forEach((slot, index) => {
            items.push({ key: `text-${index}`, type: 'text', index: index, name: slot.name, z: slot.z });
        });

        shapeSlots.forEach((slot, index) => {
            items.push({ key: `shape-${index}`, type: 'shape', index: index, name: slot.name, z: slot.z });
        });

        items.sort((a, b) => b.z - a.z);
        return items;
    }

    function updateWorkspaceLayerStyles() {
        slots.forEach((slot, index) => {
            workspace.find(`.tpl-slot[data-index="${index}"]`).css('z-index', slot.z);
        });
        textSlots.forEach((slot, index) => {
            workspace.find(`.tpl-text-slot[data-index="${index}"]`).css('z-index', slot.z);
        });
        shapeSlots.forEach((slot, index) => {
            workspace.find(`.tpl-shape-slot[data-index="${index}"]`).css('z-index', slot.z);
        });
    }

    function syncLayerValuesToSettings() {
        slots.forEach((slot, index) => {
            const row = imageSettingsContainer.find(`.image-slot-setting[data-index="${index}"]`);
            row.find('input[data-field="z"]').val(slot.z);
        });

        textSlots.forEach((slot, index) => {
            const row = textSettingsContainer.find(`.text-slot-setting[data-index="${index}"]`);
            row.find('input[data-field="z"]').val(slot.z);
        });

        shapeSlots.forEach((slot, index) => {
            const row = shapeSettingsContainer.find(`.shape-slot-setting[data-index="${index}"]`);
            row.find('input[data-field="z"]').val(slot.z);
        });
    }

    function setLayerName(type, index, value) {
        const trimmed = (value || '').trim();
        if (type === 'image' && slots[index]) {
            slots[index].name = trimmed || defaultImageName(index);
            save();
        }
        if (type === 'text' && textSlots[index]) {
            textSlots[index].name = trimmed || defaultTextName(index);
            saveTextSlots();
        }
        if (type === 'shape' && shapeSlots[index]) {
            shapeSlots[index].name = trimmed || defaultShapeName(index);
            saveShapeSlots();
        }
    }

    function escAttr(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderImageLayerFields(slot, index) {
        const radii = getImageSlotCornerRadii(slot, 10);
        const unified = getUnifiedRadiusValue(radii);
        return `
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; align-items:start;">
                <div style="grid-column:1 / -1; font-size:11px; color:#666; font-weight:600;">Pozíció és méret</div>
                <div><label>X (%)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="x" type="number" step="0.1" value="${slot.x}" style="width:100%;"></div>
                <div><label>Y (%)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="y" type="number" step="0.1" value="${slot.y}" style="width:100%;"></div>
                <div><label>Szélesség (%)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="w" type="number" step="0.1" value="${slot.w}" style="width:100%;"></div>
                <div><label>Magasság (%)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="h" type="number" step="0.1" value="${slot.h}" style="width:100%;"></div>
                <div style="grid-column:1 / -1;"><label>Réteg (z-index)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="z" type="number" step="1" value="${slot.z}" style="width:100%;"></div>

                <div style="grid-column:1 / -1; height:1px; background:#ececec; margin:4px 0;"></div>
                <div style="grid-column:1 / -1; font-size:11px; color:#666; font-weight:600;">Sarok lekerekítés</div>
                <div style="grid-column:1 / -1;"><label>Összes sarok (px)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="radius" type="number" min="0" step="1" value="${unified}" placeholder="külön értékek" style="width:100%;"></div>
                <div><label>Bal felső (px)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="radius_tl" type="number" min="0" step="1" value="${radii.tl}" style="width:100%;"></div>
                <div><label>Jobb felső (px)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="radius_tr" type="number" min="0" step="1" value="${radii.tr}" style="width:100%;"></div>
                <div><label>Jobb alsó (px)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="radius_br" type="number" min="0" step="1" value="${radii.br}" style="width:100%;"></div>
                <div><label>Bal alsó (px)</label><br><input class="layer-field" data-type="image" data-index="${index}" data-field="radius_bl" type="number" min="0" step="1" value="${radii.bl}" style="width:100%;"></div>
            </div>
        `;
    }

    function renderTextLayerFields(slot, index) {
        const fontOptions = photowooshop_admin_vars.font_families || ['hello honey', 'Densia Sans', 'Arima Koshi Regular', 'Capsuula Regular', 'Arial', 'Times New Roman', 'Courier New', 'Impact'];
        return `
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; align-items:start;">
                <div><label>X (%)</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="x" type="number" step="0.1" value="${slot.x}" style="width:100%;"></div>
                <div><label>Y (%)</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="y" type="number" step="0.1" value="${slot.y}" style="width:100%;"></div>
                <div><label>Max szélesség (%)</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="max_w" type="number" step="1" value="${slot.max_w}" style="width:100%;"></div>
                <div><label>Betűméret</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="font_size" type="number" step="1" value="${slot.font_size}" style="width:100%;"></div>
                <div><label>Szín</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="color" type="color" value="${escAttr(slot.color)}" style="width:100%;"></div>
                <div><label>Réteg (z-index)</label><br><input class="layer-field" data-type="text" data-index="${index}" data-field="z" type="number" step="1" value="${slot.z}" style="width:100%;"></div>
                <div style="grid-column:1 / -1;"><label>Betűtípus</label><br>
                    <select class="layer-field" data-type="text" data-index="${index}" data-field="font_family" style="width:100%;">${fontOptions.map(f => `<option value="${escAttr(f)}" ${slot.font_family === f ? 'selected' : ''}>${escAttr(f)}</option>`).join('')}</select>
                </div>
                <div style="grid-column:1 / -1;"><label><input class="layer-field" data-type="text" data-index="${index}" data-field="multiline" type="checkbox" ${slot.multiline ? 'checked' : ''}> Többsoros szöveg</label></div>
            </div>
        `;
    }

    function renderShapeLayerFields(slot, index) {
        const radii = getShapeCornerRadii(slot, 10);
        const unified = getUnifiedRadiusValue(radii);
        return `
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; align-items:start;">
                <div style="grid-column:1 / -1;"><label>Forma</label><br><select class="layer-field" data-type="shape" data-index="${index}" data-field="type" style="width:100%;"><option value="rect" ${slot.type === 'rect' ? 'selected' : ''}>Téglalap</option><option value="circle" ${slot.type === 'circle' ? 'selected' : ''}>Ellipszis</option></select></div>
                <div><label>X (%)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="x" type="number" step="0.1" value="${slot.x}" style="width:100%;"></div>
                <div><label>Y (%)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="y" type="number" step="0.1" value="${slot.y}" style="width:100%;"></div>
                <div><label>Szélesség (%)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="w" type="number" step="0.1" value="${slot.w}" style="width:100%;"></div>
                <div><label>Magasság (%)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="h" type="number" step="0.1" value="${slot.h}" style="width:100%;"></div>
                <div><label>Szín</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="color" type="color" value="${escAttr(slot.color)}" style="width:100%;"></div>
                <div><label>Opacity (0-1)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="opacity" type="number" step="0.01" min="0" max="1" value="${slot.opacity}" style="width:100%;"></div>
                <div><label>Réteg (z-index)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="z" type="number" step="1" value="${slot.z}" style="width:100%;"></div>
                <div><label>Összes sarok (px)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="radius" type="number" step="1" min="0" value="${unified}" placeholder="külön értékek" style="width:100%;"></div>
                <div><label>Bal felső (px)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="radius_tl" type="number" step="1" min="0" value="${radii.tl}" style="width:100%;"></div>
                <div><label>Jobb felső (px)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="radius_tr" type="number" step="1" min="0" value="${radii.tr}" style="width:100%;"></div>
                <div><label>Jobb alsó (px)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="radius_br" type="number" step="1" min="0" value="${radii.br}" style="width:100%;"></div>
                <div><label>Bal alsó (px)</label><br><input class="layer-field" data-type="shape" data-index="${index}" data-field="radius_bl" type="number" step="1" min="0" value="${radii.bl}" style="width:100%;"></div>
            </div>
        `;
    }

    function renderLayerDetails(item) {
        if (item.type === 'image') {
            const slot = slots[item.index];
            return slot ? renderImageLayerFields(slot, item.index) : '';
        }

        if (item.type === 'text') {
            const slot = textSlots[item.index];
            return slot ? renderTextLayerFields(slot, item.index) : '';
        }

        const slot = shapeSlots[item.index];
        return slot ? renderShapeLayerFields(slot, item.index) : '';
    }

    function applyLayerOrderFromList() {
        const orderedKeys = [];
        layerListContainer.find('.layer-item').each(function () {
            orderedKeys.push($(this).data('key'));
        });

        const maxZ = orderedKeys.length * 10;
        orderedKeys.forEach((key, orderIndex) => {
            const z = maxZ - (orderIndex * 10);
            const [type, idxRaw] = String(key).split('-');
            const idx = parseInt(idxRaw, 10);

            if (type === 'image' && slots[idx]) slots[idx].z = z;
            if (type === 'text' && textSlots[idx]) textSlots[idx].z = z;
            if (type === 'shape' && shapeSlots[idx]) shapeSlots[idx].z = z;
        });

        updateWorkspaceLayerStyles();
        syncLayerValuesToSettings();
        save();
        saveTextSlots();
        saveShapeSlots();
        renderLayerManager();
    }

    function renderLayerManager() {
        ensureSlotDefaults();
        layerListContainer.empty();

        const items = getLayerItemsSorted();
        if (!items.length) {
            layerListContainer.html('<p style="margin:6px; color:#777;">Nincs reteg.</p>');
            return;
        }

        if (!expandedLayerKey || !items.some((it) => it.key === expandedLayerKey)) {
            expandedLayerKey = items[0].key;
        }

        items.forEach((item) => {
            const typeLabel = item.type === 'image' ? 'Kep' : (item.type === 'text' ? 'Szoveg' : 'Alakzat');
            const opened = item.key === expandedLayerKey;
            layerListContainer.append(`
                <div class="layer-item" draggable="true" data-key="${item.key}" data-type="${item.type}" data-index="${item.index}" style="padding:10px; border:1px solid #e6e6e6; border-radius:10px; background:#fff; margin-bottom:8px;">
                    <div class="layer-item-header" style="display:flex; align-items:center; gap:8px; cursor:pointer; width:100%;">
                        <span class="layer-handle">≡</span>
                        <span class="layer-type layer-${item.type}">${typeLabel}</span>
                        <input type="text" class="layer-name-input" value="${escAttr(item.name || '')}" data-type="${item.type}" data-index="${item.index}" title="Reteg neve" style="flex:1; min-width:0; max-width:none;">
                        <span class="layer-z" style="white-space:nowrap;">z: ${item.z}</span>
                        <span style="font-weight:bold; color:#666;">${opened ? '−' : '+'}</span>
                    </div>
                    <div class="layer-item-body" style="display:${opened ? 'block' : 'none'}; margin-top:10px; padding-top:10px; border-top:1px solid #ececec; width:100%;">
                        ${renderLayerDetails(item)}
                    </div>
                </div>
            `);
        });
    }

    layerPanel.on('click', '[data-layer-add="image"]', function () {
        addBtn.trigger('click');
    });

    layerPanel.on('click', '[data-layer-add="text"]', function () {
        addTextBtn.trigger('click');
    });

    layerPanel.on('click', '[data-layer-add="shape"]', function () {
        addShapeBtn.trigger('click');
    });

    layerListContainer.on('click', '.layer-item-header', function (e) {
        if ($(e.target).is('input, select, button, option')) {
            return;
        }

        const item = $(this).closest('.layer-item');
        const key = item.data('key');
        expandedLayerKey = expandedLayerKey === key ? null : key;
        renderLayerManager();
    });

    layerListContainer.on('input change', '.layer-field', function () {
        const type = $(this).data('type');
        const index = parseInt($(this).data('index'), 10);
        const field = $(this).data('field');

        if (type === 'image' && slots[index]) {
            let val = $(this).attr('type') === 'number' ? parseFloat($(this).val()) : $(this).val();
            if (Number.isNaN(val)) {
                val = 0;
            }

            if (field === 'radius') {
                val = Math.max(0, val);
                slots[index].radius = val;
                slots[index].radii = { tl: val, tr: val, br: val, bl: val };
            } else if (field === 'radius_tl' || field === 'radius_tr' || field === 'radius_br' || field === 'radius_bl') {
                val = Math.max(0, val);
                const key = field.replace('radius_', '');
                const radii = getImageSlotCornerRadii(slots[index], 10);
                radii[key] = val;
                slots[index].radii = radii;
                slots[index].radius = radii.tl;
            } else if (field === 'z') {
                const z = parseInt(val, 10);
                slots[index].z = Number.isNaN(z) ? 10 : z;
            } else {
                slots[index][field] = val;
            }

            const visualEl = workspace.find(`.tpl-slot[data-index="${index}"]`);
            visualEl.css({
                left: slots[index].x + '%',
                top: slots[index].y + '%',
                width: slots[index].w + '%',
                height: slots[index].h + '%',
                zIndex: slots[index].z,
                borderRadius: getImageSlotBorderRadiusCss(slots[index], 10)
            });

            save();
            if (field === 'z') {
                renderLayerManager();
            }
            return;
        }

        if (type === 'text' && textSlots[index]) {
            let val;
            if ($(this).attr('type') === 'checkbox') {
                val = $(this).is(':checked');
            } else if ($(this).attr('type') === 'number') {
                val = parseFloat($(this).val()) || 0;
            } else {
                val = $(this).val();
            }

            textSlots[index][field] = val;
            const visualEl = workspace.find(`.tpl-text-slot[data-index="${index}"]`);
            if (field === 'x') visualEl.css('left', val + '%');
            if (field === 'y') visualEl.css('top', val + '%');
            if (field === 'max_w') visualEl.css('width', val + '%');
            if (field === 'color') visualEl.css('color', val);
            if (field === 'font_family') visualEl.css('font-family', `"${val}", cursive, sans-serif`);
            if (field === 'font_size') visualEl.css('font-size', Math.max(10, val * 0.5) + 'px');
            if (field === 'z') visualEl.css('z-index', parseInt(val, 10) || 50);

            saveTextSlots();
            if (field === 'z') {
                renderLayerManager();
            }
            return;
        }

        if (type === 'shape' && shapeSlots[index]) {
            let val;
            if ($(this).attr('type') === 'number' || field === 'opacity') {
                val = parseFloat($(this).val());
                if (Number.isNaN(val)) val = 0;
            } else {
                val = $(this).val();
            }

            if (field === 'radius') {
                val = Math.max(0, val);
                shapeSlots[index].radius = val;
                shapeSlots[index].radii = { tl: val, tr: val, br: val, bl: val };
                const row = $(this).closest('.layer-item-body');
                row.find('input[data-field="radius_tl"]').val(val);
                row.find('input[data-field="radius_tr"]').val(val);
                row.find('input[data-field="radius_br"]').val(val);
                row.find('input[data-field="radius_bl"]').val(val);
            } else if (field === 'radius_tl' || field === 'radius_tr' || field === 'radius_br' || field === 'radius_bl') {
                val = Math.max(0, val);
                const key = field.replace('radius_', '');
                const radii = getShapeCornerRadii(shapeSlots[index], 10);
                radii[key] = val;
                shapeSlots[index].radii = radii;
                shapeSlots[index].radius = radii.tl;
                $(this).closest('.layer-item-body').find('input[data-field="radius"]').val(getUnifiedRadiusValue(radii));
            } else {
                shapeSlots[index][field] = val;
            }

            if (field === 'opacity') {
                if (shapeSlots[index][field] < 0) shapeSlots[index][field] = 0;
                if (shapeSlots[index][field] > 1) shapeSlots[index][field] = 1;
            }

            const visualEl = workspace.find(`.tpl-shape-slot[data-index="${index}"]`);
            applyShapeStyle(visualEl, shapeSlots[index]);
            saveShapeSlots();
            if (field === 'z') {
                renderLayerManager();
            }
        }
    });

    function renderImageSlotSettings() {
        imageSettingsContainer.empty();

        slots.forEach((slot, index) => {
            const safeRadii = getImageSlotCornerRadii(slot, 10);
            const safeRadius = getUnifiedRadiusValue(safeRadii);
            const safeZ = parseInt(slot.z, 10);

            const settingsHtml = `
                <div class="image-slot-setting" data-index="${index}" style="background:#fff; border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:4px; position:relative;">
                    <h5 style="margin:0 0 10px;">${slot.name || defaultImageName(index)}</h5>
                    <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px;">
                        <div>
                            <label>Összes sarok:</label><br>
                            <input type="number" data-field="radius" value="${safeRadius}" min="0" step="1" style="width:70px;" placeholder="külön értékek"> px
                        </div>
                        <div>
                            <label>Bal felső:</label><br>
                            <input type="number" data-field="radius_tl" value="${safeRadii.tl}" min="0" step="1" style="width:70px;"> px
                        </div>
                        <div>
                            <label>Jobb felső:</label><br>
                            <input type="number" data-field="radius_tr" value="${safeRadii.tr}" min="0" step="1" style="width:70px;"> px
                        </div>
                        <div>
                            <label>Jobb alsó:</label><br>
                            <input type="number" data-field="radius_br" value="${safeRadii.br}" min="0" step="1" style="width:70px;"> px
                        </div>
                        <div>
                            <label>Bal alsó:</label><br>
                            <input type="number" data-field="radius_bl" value="${safeRadii.bl}" min="0" step="1" style="width:70px;"> px
                        </div>
                        <div style="grid-column:1 / -1;">
                            <div style="font-size:11px; color:#666; margin:4px 0 6px;">Sarok térkép (élő előnézet):</div>
                            <div class="image-corner-map-preview" style="position:relative; width:130px; height:80px; border:2px dashed #999; background:linear-gradient(135deg, #f7f7f7, #ffffff); border-radius:${safeRadii.tl}px ${safeRadii.tr}px ${safeRadii.br}px ${safeRadii.bl}px;">
                                <span data-corner="tl" style="position:absolute; left:6px; top:4px; font-size:10px; color:#333; background:rgba(255,255,255,0.8); padding:1px 4px; border-radius:10px;">TL ${safeRadii.tl}</span>
                                <span data-corner="tr" style="position:absolute; right:6px; top:4px; font-size:10px; color:#333; background:rgba(255,255,255,0.8); padding:1px 4px; border-radius:10px;">TR ${safeRadii.tr}</span>
                                <span data-corner="br" style="position:absolute; right:6px; bottom:4px; font-size:10px; color:#333; background:rgba(255,255,255,0.8); padding:1px 4px; border-radius:10px;">BR ${safeRadii.br}</span>
                                <span data-corner="bl" style="position:absolute; left:6px; bottom:4px; font-size:10px; color:#333; background:rgba(255,255,255,0.8); padding:1px 4px; border-radius:10px;">BL ${safeRadii.bl}</span>
                            </div>
                        </div>
                        <div>
                            <label>Réteg (z-index):</label><br>
                            <input type="number" data-field="z" value="${Number.isNaN(safeZ) ? 10 : safeZ}" step="1" style="width:70px;">
                        </div>
                    </div>
                </div>
            `;

            imageSettingsContainer.append(settingsHtml);
        });
    }

    imageSettingsContainer.on('input change', 'input', function () {
        const idx = $(this).closest('.image-slot-setting').data('index');
        const field = $(this).data('field');

        if (!slots[idx]) {
            return;
        }

        let val = parseFloat($(this).val());
        if (Number.isNaN(val)) {
            val = 0;
        }

        if (field === 'radius') {
            val = Math.max(0, val);
            slots[idx].radius = val;
            slots[idx].radii = { tl: val, tr: val, br: val, bl: val };
            workspace.find(`.tpl-slot[data-index="${idx}"]`).css('border-radius', getImageSlotBorderRadiusCss(slots[idx], 10));
            const row = imageSettingsContainer.find(`.image-slot-setting[data-index="${idx}"]`);
            row.find('input[data-field="radius_tl"]').val(val);
            row.find('input[data-field="radius_tr"]').val(val);
            row.find('input[data-field="radius_br"]').val(val);
            row.find('input[data-field="radius_bl"]').val(val);
            updateCornerMapPreview(row, slots[idx].radii);
            $(this).val(val);
        }

        if (field === 'radius_tl' || field === 'radius_tr' || field === 'radius_br' || field === 'radius_bl') {
            val = Math.max(0, val);
            const key = field.replace('radius_', '');
            const radii = getImageSlotCornerRadii(slots[idx], 10);
            radii[key] = val;
            slots[idx].radii = radii;
            slots[idx].radius = radii.tl;
            workspace.find(`.tpl-slot[data-index="${idx}"]`).css('border-radius', getImageSlotBorderRadiusCss(slots[idx], 10));

            const unified = getUnifiedRadiusValue(radii);
            const row = imageSettingsContainer.find(`.image-slot-setting[data-index="${idx}"]`);
            row.find('input[data-field="radius"]').val(unified);
            updateCornerMapPreview(row, radii);
            $(this).val(val);
        }

        if (field === 'z') {
            const z = parseInt(val, 10);
            slots[idx].z = Number.isNaN(z) ? 10 : z;
            workspace.find(`.tpl-slot[data-index="${idx}"]`).css('z-index', slots[idx].z);
            $(this).val(slots[idx].z);
            renderLayerManager();
        }

        save();
    });

    addTextBtn.on('click', function () {
        textSlots.push({
            x: 50, y: 50, max_w: 80,
            color: '#ffffff',
            multiline: false,
            font_family: 'hello honey',
            font_size: 50,
            z: 50,
            name: defaultTextName(textSlots.length)
        });
        renderTextSlots();
        renderLayerManager();
        saveTextSlots();
    });

    textSettingsContainer.on('input change', 'input, select', function () {
        const idx = $(this).closest('.text-slot-setting').data('index');
        const field = $(this).data('field');
        let val = $(this).val();

        if ($(this).attr('type') === 'checkbox') {
            val = $(this).is(':checked');
        } else if ($(this).attr('type') === 'number') {
            val = parseFloat(val) || 0;
        }

        textSlots[idx][field] = val;

        // Update visual
        const visualEl = workspace.find(`.tpl-text-slot[data-index="${idx}"]`);
        if (field === 'x') visualEl.css('left', val + '%');
        if (field === 'y') visualEl.css('top', val + '%');
        if (field === 'max_w') visualEl.css('width', val + '%');
        if (field === 'color') visualEl.css('color', val);
        if (field === 'font_family') visualEl.css('font-family', `"${val}", cursive, sans-serif`);
        if (field === 'font_size') visualEl.css('font-size', Math.max(10, val * 0.5) + 'px');
        if (field === 'z') visualEl.css('z-index', parseInt(val, 10) || 50);

        saveTextSlots();
        if (field === 'z') {
            renderLayerManager();
        }
    });

    textSettingsContainer.on('click', '.remove-text-slot', function () {
        const idx = $(this).closest('.text-slot-setting').data('index');
        textSlots.splice(idx, 1);
        renderTextSlots();
        renderLayerManager();
        saveTextSlots();
    });

    addShapeBtn.on('click', function () {
        shapeSlots.push({
            type: 'rect',
            x: 50,
            y: 50,
            w: 40,
            h: 20,
            color: '#000000',
            opacity: 0.45,
            radius: 10,
            z: 30,
            name: defaultShapeName(shapeSlots.length)
        });
        renderShapeSlots();
        renderLayerManager();
        saveShapeSlots();
    });

    shapeSettingsContainer.on('input change', 'input, select', function () {
        const idx = $(this).closest('.shape-slot-setting').data('index');
        const field = $(this).data('field');
        let val = $(this).val();

        if ($(this).attr('type') === 'number' || field === 'opacity') {
            val = parseFloat(val);
            if (Number.isNaN(val)) val = 0;
        }

        if (!shapeSlots[idx]) {
            return;
        }

        shapeSlots[idx][field] = val;

        if (field === 'opacity') {
            if (shapeSlots[idx][field] < 0) shapeSlots[idx][field] = 0;
            if (shapeSlots[idx][field] > 1) shapeSlots[idx][field] = 1;
            $(this).val(shapeSlots[idx][field]);
        }

        if (field === 'radius') {
            if (shapeSlots[idx][field] < 0) shapeSlots[idx][field] = 0;
            $(this).val(shapeSlots[idx][field]);
        }

        const visualEl = workspace.find(`.tpl-shape-slot[data-index="${idx}"]`);
        applyShapeStyle(visualEl, shapeSlots[idx]);
        saveShapeSlots();
        if (field === 'z') {
            renderLayerManager();
        }
    });

    shapeSettingsContainer.on('click', '.remove-shape-slot', function () {
        const idx = $(this).closest('.shape-slot-setting').data('index');
        shapeSlots.splice(idx, 1);
        renderShapeSlots();
        renderLayerManager();
        saveShapeSlots();
    });

    function renderTextSlots() {
        workspace.find('.tpl-text-slot').remove();
        textSettingsContainer.empty();

        const fontOptions = photowooshop_admin_vars.font_families || ['hello honey', 'Densia Sans', 'Arima Koshi Regular', 'Capsuula Regular', 'Arial', 'Times New Roman', 'Courier New', 'Impact'];

        textSlots.forEach((slot, index) => {
            // Workspace Visual Render
            const el = $('<div class="tpl-text-slot">').attr('data-index', index);
            if (!slot.name) slot.name = defaultTextName(index);
            el.text(slot.name);
            const safeZ = parseInt(slot.z, 10);
            el.css({
                position: 'absolute',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxSizing: 'border-box',
                transform: 'translate(-50%, -50%)',
                cursor: 'move',
                background: 'rgba(255, 193, 7, 0.4)',
                border: '2px dashed #ffc107',
                padding: '5px',
                fontWeight: 'bold',
                whiteSpace: 'nowrap',
                zIndex: Number.isNaN(safeZ) ? 50 : safeZ,
                left: slot.x + '%',
                top: slot.y + '%',
                width: slot.max_w + '%',
                color: slot.color,
                fontFamily: `"${slot.font_family}", cursive, sans-serif`,
                fontSize: Math.max(10, slot.font_size * 0.5) + 'px'
            });
            workspace.append(el);
            makeTextInteractable(el[0]);

            // Settings Render
            const settingsHtml = `
                <div class="text-slot-setting" data-index="${index}" style="background:#fff; border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:4px; position:relative;">
                    <button type="button" class="remove-text-slot button button-small" style="position:absolute; right:10px; top:10px; color:red; border-color:red;">Törlés</button>
                    <h5>${slot.name}</h5>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <div>
                            <label>X (közép):</label><br>
                            <input type="number" data-field="x" value="${slot.x}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Y (közép):</label><br>
                            <input type="number" data-field="y" value="${slot.y}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Max. Szélesség:</label><br>
                            <input type="number" data-field="max_w" value="${slot.max_w}" step="1" max="100" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Szín:</label><br>
                            <input type="color" data-field="color" value="${slot.color}">
                        </div>
                        <div>
                            <label>Betűtípus:</label><br>
                            <select data-field="font_family">
                                ${fontOptions.map(f => `<option value="${f}" ${slot.font_family === f ? 'selected' : ''}>${f}</option>`).join('')}
                            </select>
                        </div>
                        <div>
                            <label>Méretezés (1-100):</label><br>
                            <input type="number" data-field="font_size" value="${slot.font_size}" step="1" max="200" style="width:70px;">
                        </div>
                        <div>
                            <label>Réteg (z-index):</label><br>
                            <input type="number" data-field="z" value="${Number.isNaN(safeZ) ? 50 : safeZ}" step="1" style="width:70px;">
                        </div>
                        <div style="width:100%; margin-top:5px;">
                            <label><input type="checkbox" data-field="multiline" ${slot.multiline ? 'checked' : ''}> Több soros szöveg engedélyezése</label>
                        </div>
                    </div>
                </div>
            `;
            textSettingsContainer.append(settingsHtml);
        });
    }

    function applyShapeStyle(el, slot) {
        const opacity = typeof slot.opacity === 'number' ? slot.opacity : 0.45;
        const z = typeof slot.z === 'number' ? slot.z : 30;
        const radiiCss = slot.type === 'circle' ? '9999px' : getShapeBorderRadiusCss(slot, 10);

        el.css({
            position: 'absolute',
            transform: 'translate(-50%, -50%)',
            left: slot.x + '%',
            top: slot.y + '%',
            width: slot.w + '%',
            height: slot.h + '%',
            background: slot.color,
            opacity: opacity,
            borderRadius: radiiCss,
            border: '1px dashed rgba(255,255,255,0.8)',
            boxSizing: 'border-box',
            zIndex: z,
            cursor: 'move'
        });
    }

    let draggedLayerItem = null;

    layerListContainer.on('dragstart', '.layer-item', function (e) {
        draggedLayerItem = this;
        $(this).addClass('dragging');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', $(this).data('key'));
    });

    layerListContainer.on('dragover', '.layer-item', function (e) {
        e.preventDefault();
        if (!draggedLayerItem || draggedLayerItem === this) return;

        const rect = this.getBoundingClientRect();
        const isAfter = (e.originalEvent.clientY - rect.top) > (rect.height / 2);

        if (isAfter) {
            this.parentNode.insertBefore(draggedLayerItem, this.nextSibling);
        } else {
            this.parentNode.insertBefore(draggedLayerItem, this);
        }
    });

    layerListContainer.on('dragend', '.layer-item', function () {
        $(this).removeClass('dragging');
        draggedLayerItem = null;
        applyLayerOrderFromList();
    });

    layerListContainer.on('change', '.layer-name-input', function () {
        const type = $(this).data('type');
        const index = parseInt($(this).data('index'), 10);
        setLayerName(type, index, $(this).val());

        if (type === 'image') {
            imageSettingsContainer.find(`.image-slot-setting[data-index="${index}"] h5`).text(slots[index].name || defaultImageName(index));
        }

        if (type === 'text') {
            const labelEl = workspace.find(`.tpl-text-slot[data-index="${index}"]`);
            if (labelEl.length) {
                labelEl.text(textSlots[index].name || defaultTextName(index));
            }
            textSettingsContainer.find(`.text-slot-setting[data-index="${index}"] h5`).text(textSlots[index].name || defaultTextName(index));
        }

        renderLayerManager();
    });

    function renderShapeSlots() {
        workspace.find('.tpl-shape-slot').remove();
        shapeSettingsContainer.empty();

        shapeSlots.forEach((slot, index) => {
            const safeSlot = Object.assign({
                type: 'rect',
                x: 50,
                y: 50,
                w: 40,
                h: 20,
                color: '#000000',
                opacity: 0.45,
                radius: 10,
                radii: { tl: 10, tr: 10, br: 10, bl: 10 },
                z: 30,
                name: defaultShapeName(index)
            }, slot);
            safeSlot.radii = getShapeCornerRadii(safeSlot, 10);
            shapeSlots[index] = safeSlot;

            const el = $('<div class="tpl-shape-slot"></div>').attr('data-index', index);
            applyShapeStyle(el, safeSlot);
            workspace.append(el);
            makeShapeInteractable(el[0]);

            const settingsHtml = `
                <div class="shape-slot-setting" data-index="${index}" style="background:#fff; border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:4px; position:relative;">
                    <button type="button" class="remove-shape-slot button button-small" style="position:absolute; right:10px; top:10px; color:red; border-color:red;">Törlés</button>
                    <h5>${safeSlot.name}</h5>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <div>
                            <label>Típus:</label><br>
                            <select data-field="type">
                                <option value="rect" ${safeSlot.type === 'rect' ? 'selected' : ''}>Téglalap</option>
                                <option value="circle" ${safeSlot.type === 'circle' ? 'selected' : ''}>Ovális/Kör</option>
                            </select>
                        </div>
                        <div>
                            <label>X (közép):</label><br>
                            <input type="number" data-field="x" value="${safeSlot.x}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Y (közép):</label><br>
                            <input type="number" data-field="y" value="${safeSlot.y}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Szélesség:</label><br>
                            <input type="number" data-field="w" value="${safeSlot.w}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Magasság:</label><br>
                            <input type="number" data-field="h" value="${safeSlot.h}" step="0.1" style="width:70px;"> %
                        </div>
                        <div>
                            <label>Szín:</label><br>
                            <input type="color" data-field="color" value="${safeSlot.color}">
                        </div>
                        <div>
                            <label>Átlátszóság (0-1):</label><br>
                            <input type="number" data-field="opacity" value="${safeSlot.opacity}" min="0" max="1" step="0.05" style="width:70px;">
                        </div>
                        <div>
                            <label>Sarok lekerekítés:</label><br>
                            <input type="number" data-field="radius" value="${safeSlot.radius}" min="0" step="1" style="width:70px;"> px
                        </div>
                        <div>
                            <label>Réteg (z-index):</label><br>
                            <input type="number" data-field="z" value="${safeSlot.z}" step="1" style="width:70px;">
                        </div>
                    </div>
                </div>
            `;

            shapeSettingsContainer.append(settingsHtml);
        });
    }

    function makeTextInteractable(el) {
        interact(el)
            .draggable({
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).data('index');
                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        let currX = parseFloat(target.style.left) || 0;
                        let currY = parseFloat(target.style.top) || 0;

                        currX += (event.dx / parentW) * 100;
                        currY += (event.dy / parentH) * 100;

                        target.style.left = currX + '%';
                        target.style.top = currY + '%';

                        textSlots[idx].x = parseFloat(currX.toFixed(1));
                        textSlots[idx].y = parseFloat(currY.toFixed(1));

                        // Update settings input box
                        textSettingsContainer.find(`.text-slot-setting[data-index="${idx}"] input[data-field="x"]`).val(textSlots[idx].x);
                        textSettingsContainer.find(`.text-slot-setting[data-index="${idx}"] input[data-field="y"]`).val(textSlots[idx].y);

                        saveTextSlots();
                    }
                },
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    })
                ]
            })
            .resizable({
                edges: { left: true, right: true },
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).data('index');
                        const parentW = workspace.width();

                        let w = (event.rect.width / parentW) * 100;
                        if (w > 100) w = 100;
                        if (w < 5) w = 5;

                        target.style.width = w + '%';

                        textSlots[idx].max_w = Math.round(w);
                        textSettingsContainer.find(`.text-slot-setting[data-index="${idx}"] input[data-field="max_w"]`).val(textSlots[idx].max_w);

                        saveTextSlots();
                    }
                }
            });
    }

    function makeShapeInteractable(el) {
        interact(el)
            .draggable({
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).data('index');
                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        let currX = parseFloat(target.style.left) || 0;
                        let currY = parseFloat(target.style.top) || 0;

                        currX += (event.dx / parentW) * 100;
                        currY += (event.dy / parentH) * 100;

                        target.style.left = currX + '%';
                        target.style.top = currY + '%';

                        shapeSlots[idx].x = parseFloat(currX.toFixed(1));
                        shapeSlots[idx].y = parseFloat(currY.toFixed(1));

                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="x"]`).val(shapeSlots[idx].x);
                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="y"]`).val(shapeSlots[idx].y);

                        saveShapeSlots();
                    }
                },
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    })
                ]
            })
            .resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).data('index');

                        let x = parseFloat(target.style.left) || 0;
                        let y = parseFloat(target.style.top) || 0;

                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        let w = (event.rect.width / parentW) * 100;
                        let h = (event.rect.height / parentH) * 100;
                        x += (event.deltaRect.left / parentW) * 100;
                        y += (event.deltaRect.top / parentH) * 100;

                        if (w < 3) w = 3;
                        if (h < 3) h = 3;

                        target.style.width = w + '%';
                        target.style.height = h + '%';
                        target.style.left = x + '%';
                        target.style.top = y + '%';

                        shapeSlots[idx].w = parseFloat(w.toFixed(1));
                        shapeSlots[idx].h = parseFloat(h.toFixed(1));
                        shapeSlots[idx].x = parseFloat(x.toFixed(1));
                        shapeSlots[idx].y = parseFloat(y.toFixed(1));

                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="w"]`).val(shapeSlots[idx].w);
                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="h"]`).val(shapeSlots[idx].h);
                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="x"]`).val(shapeSlots[idx].x);
                        shapeSettingsContainer.find(`.shape-slot-setting[data-index="${idx}"] input[data-field="y"]`).val(shapeSlots[idx].y);

                        saveShapeSlots();
                    }
                },
                modifiers: [
                    interact.modifiers.restrictSize({
                        min: { width: 20, height: 20 }
                    }),
                    interact.modifiers.restrictRect({
                        restriction: 'parent'
                    })
                ]
            });
    }

    function updateBackground(url) {
        const img = new Image();
        img.src = url;
        img.onload = function () {
            const aspect = img.width / img.height;
            const maxWidth = 800;
            const w = maxWidth;
            const h = maxWidth / aspect;

            workspace.css({
                width: w + 'px',
                height: h + 'px',
                backgroundImage: 'url(' + url + ')',
                backgroundSize: '100% 100%'
            });
            renderSlots();
            renderShapeSlots();
            renderTextSlots();
            renderLayerManager();
        };
    }

    function renderSlots() {
        workspace.find('.tpl-slot').not('#tpl-text').remove();
        slots.forEach((slot, index) => {
            if (typeof slot.z !== 'number') {
                slot.z = 10;
            }
            if (typeof slot.radius !== 'number') {
                slot.radius = 10;
            }
            slot.radii = getImageSlotCornerRadii(slot, 10);
            if (!slot.name) {
                slot.name = defaultImageName(index);
            }
            const el = $('<div class="tpl-slot">').attr('data-index', index);
            el.text(index + 1);
            el.append('<span class="remove-slot">×</span>');

            el.css({
                left: slot.x + '%',
                top: slot.y + '%',
                width: slot.w + '%',
                height: slot.h + '%',
                zIndex: slot.z,
                borderRadius: getImageSlotBorderRadiusCss(slot, 10)
            });

            workspace.append(el);
            makeInteractable(el[0]);
        });

        renderImageSlotSettings();
    }

    function makeInteractable(el) {
        interact(el)
            .draggable({
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).attr('data-index');

                        let x = parseFloat(target.style.left) || 0;
                        let y = parseFloat(target.style.top) || 0;

                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        x += (event.dx / parentW) * 100;
                        y += (event.dy / parentH) * 100;

                        target.style.left = x + '%';
                        target.style.top = y + '%';

                        slots[idx].x = x;
                        slots[idx].y = y;
                        save();
                    },
                },
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    })
                ]
            })
            .resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                listeners: {
                    move(event) {
                        const target = event.target;
                        const idx = $(target).attr('data-index');

                        let x = parseFloat(target.style.left) || 0;
                        let y = parseFloat(target.style.top) || 0;

                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        let w = (event.rect.width / parentW) * 100;
                        let h = (event.rect.height / parentH) * 100;
                        x += (event.deltaRect.left / parentW) * 100;
                        y += (event.deltaRect.top / parentH) * 100;

                        target.style.width = w + '%';
                        target.style.height = h + '%';
                        target.style.left = x + '%';
                        target.style.top = y + '%';

                        slots[idx].w = w;
                        slots[idx].h = h;
                        slots[idx].x = x;
                        slots[idx].y = y;
                        save();
                    }
                },
                modifiers: [
                    interact.modifiers.restrictSize({
                        min: { width: 20, height: 20 }
                    }),
                    interact.modifiers.restrictRect({
                        restriction: 'parent'
                    })
                ]
            });
    }

    function save() {
        slotsInput.val(JSON.stringify(slots));
    }

    addBtn.on('click', function () {
        slots.push({
            x: 10,
            y: 10,
            w: 20,
            h: 20,
            z: 10,
            radius: 10,
            radii: { tl: 10, tr: 10, br: 10, bl: 10 },
            name: defaultImageName(slots.length)
        });
        renderSlots();
        renderLayerManager();
        save();
    });

    workspace.on('click', '.remove-slot', function (e) {
        e.stopPropagation();
        const idx = $(this).parent().attr('data-index');
        slots.splice(idx, 1);
        renderSlots();
        renderLayerManager();
        save();
    });

    // --- Perspective Mockup Logic ---
    const corners = ['tl', 'tr', 'bl', 'br'];
    corners.forEach(c => {
        workspace.append(`<div class="mockup-handle" data-corner="${c}" style="display:none;"></div>`);
    });
    workspace.append('<canvas id="mockup-preview-canvas" style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:50; opacity: 0.85;"></canvas>');

    let currentMockupIndex = null;
    let templateBgImg = null;

    if (photowooshop_admin_vars.bg_url) {
        templateBgImg = new Image();
        templateBgImg.crossOrigin = "anonymous";
        templateBgImg.src = photowooshop_admin_vars.bg_url;
    }

    $('.mockup-visual-btn').on('click', function () {
        const idx = $(this).data('index');
        const url = $('#m_url_' + idx).val();

        if (!url) {
            alert('Kérlek előbb adj meg egy háttérképet a mockupnak!');
            return;
        }

        currentMockupIndex = idx;
        $('.tpl-slot, .tpl-shape-slot, .tpl-text-slot, #tpl-text').hide();
        $('.mockup-visual-btn').text('Perspektíva beállítás').css('background', '#007cba');
        $(this).text('Szerkesztés alatt...').css('background', '#ff5722');

        updateMockupBackground(url, idx);
    });

    function updateMockupBackground(url, idx) {
        const img = new Image();
        img.src = url;
        img.onload = function () {
            const aspect = img.width / img.height;
            const maxWidth = 800;
            const w = maxWidth;
            const h = maxWidth / aspect;

            workspace.css({
                width: w + 'px',
                height: h + 'px',
                backgroundImage: 'url(' + url + ')',
                backgroundSize: '100% 100%'
            });

            showPerspectiveHandles(idx);
        };
    }

    function showPerspectiveHandles(idx) {
        $('.mockup-handle, #mockup-preview-canvas').show();

        corners.forEach(c => {
            const val = $('#m_' + c + '_' + idx).val() || (c === 'tl' ? '10,10' : (c === 'tr' ? '90,10' : (c === 'bl' ? '10,90' : '90,90')));
            const [x, y] = val.split(',').map(parseFloat);
            $(`.mockup-handle[data-corner="${c}"]`).css({
                left: x + '%',
                top: y + '%'
            });
        });

        updatePerspectiveOverlay();
        initPerspectiveInteract();
    }

    function updatePerspectiveOverlay() {
        if (!templateBgImg || !templateBgImg.complete) return;

        const parentW = workspace.width();
        const parentH = workspace.height();

        const canvas = document.getElementById('mockup-preview-canvas');
        canvas.width = parentW;
        canvas.height = parentH;

        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, parentW, parentH);

        const pts = {};
        corners.forEach(c => {
            const h = $(`.mockup-handle[data-corner="${c}"]`);
            pts[c] = [
                (parseFloat(h[0].style.left) / 100) * parentW,
                (parseFloat(h[0].style.top) / 100) * parentH
            ];
        });

        drawPerspective(ctx, templateBgImg, pts.tl, pts.tr, pts.br, pts.bl);

        // Draw an outline around the warped area for visibility
        ctx.strokeStyle = "#ff5722";
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(pts.tl[0], pts.tl[1]);
        ctx.lineTo(pts.tr[0], pts.tr[1]);
        ctx.lineTo(pts.br[0], pts.br[1]);
        ctx.lineTo(pts.bl[0], pts.bl[1]);
        ctx.closePath();
        ctx.stroke();
    }

    function drawPerspective(ctx, img, tl, tr, br, bl) {
        const steps = 10; // Lower resolution for real-time admin preview
        const width = img.width;
        const height = img.height;

        for (let y = 0; y < steps; y++) {
            for (let x = 0; x < steps; x++) {
                const x1 = x / steps;
                const y1 = y / steps;
                const x2 = (x + 1) / steps;
                const y2 = (y + 1) / steps;

                const p1 = lerpQuad(tl, tr, br, bl, x1, y1);
                const p2 = lerpQuad(tl, tr, br, bl, x2, y1);
                const p3 = lerpQuad(tl, tr, br, bl, x2, y2);
                const p4 = lerpQuad(tl, tr, br, bl, x1, y2);

                drawTriangle(ctx, img,
                    x1 * width, y1 * height,
                    x2 * width, y1 * height,
                    x1 * width, y2 * height,
                    p1[0], p1[1], p2[0], p2[1], p4[0], p4[1]);

                drawTriangle(ctx, img,
                    x2 * width, y1 * height,
                    x2 * width, y2 * height,
                    x1 * width, y2 * height,
                    p2[0], p2[1], p3[0], p3[1], p4[0], p4[1]);
            }
        }
    }

    function lerpQuad(tl, tr, br, bl, u, v) {
        const top = [tl[0] + (tr[0] - tl[0]) * u, tl[1] + (tr[1] - tl[1]) * u];
        const bottom = [bl[0] + (br[0] - bl[0]) * u, bl[1] + (br[1] - bl[1]) * u];
        return [top[0] + (bottom[0] - top[0]) * v, top[1] + (bottom[1] - top[1]) * v];
    }

    function drawTriangle(ctx, img, u0, v0, u1, v1, u2, v2, x0, y0, x1, y1, x2, y2) {
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(x0, y0);
        ctx.lineTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.closePath();
        ctx.clip();

        const delta = u0 * v1 + v0 * u2 + u1 * v2 - v1 * u2 - v0 * u1 - u0 * v2;
        if (delta === 0) { ctx.restore(); return; }

        const deltaA = x0 * v1 + v0 * x2 + x1 * v2 - v1 * x2 - v0 * x1 - x0 * v2;
        const deltaB = u0 * x1 + x0 * u2 + u1 * x2 - x1 * u2 - x0 * u1 - u0 * x2;
        const deltaC = u0 * v1 * x2 + v0 * x1 * u2 + x0 * u1 * v2 - x0 * v1 * u2 - v0 * u1 * x2 - u0 * x1 * v2;
        const deltaD = y0 * v1 + v0 * y2 + y1 * v2 - v1 * y2 - v0 * y1 - y0 * v2;
        const deltaE = u0 * y1 + y0 * u2 + u1 * y2 - y1 * u2 - y0 * u1 - u0 * y2;
        const deltaF = u0 * v1 * y2 + v0 * y1 * u2 + y0 * u1 * v2 - y0 * v1 * u2 - v0 * u1 * y2 - u0 * y1 * v2;

        ctx.transform(
            deltaA / delta, deltaD / delta,
            deltaB / delta, deltaE / delta,
            deltaC / delta, deltaF / delta
        );
        ctx.drawImage(img, 0, 0);
        ctx.restore();
    }

    function initPerspectiveInteract() {
        interact('.mockup-handle')
            .draggable({
                listeners: {
                    move(event) {
                        const target = event.target;
                        const corner = $(target).data('corner');
                        const parentW = workspace.width();
                        const parentH = workspace.height();

                        let x = parseFloat(target.style.left) || 0;
                        let y = parseFloat(target.style.top) || 0;

                        x += (event.dx / parentW) * 100;
                        y += (event.dy / parentH) * 100;

                        target.style.left = x + '%';
                        target.style.top = y + '%';

                        $('#m_' + corner + '_' + currentMockupIndex).val(`${x.toFixed(1)},${y.toFixed(1)}`);
                        updatePerspectiveOverlay();
                    }
                }
            });
    }

    bgInput.on('change', function () {
        currentMockupIndex = null;
        $('.tpl-slot, .tpl-shape-slot, .tpl-text-slot, #tpl-text').show();
        $('.mockup-handle, #mockup-preview-canvas').hide();
        $('.mockup-visual-btn').text('Perspektíva beállítás').css('background', '#007cba');
        updateBackground($(this).val());
    });

    $('body').on('click', '.photowooshop-browse-media', function (e) {
        e.preventDefault();
        var uploader = wp.media({
            title: 'Háttérkép kiválasztása',
            button: { text: 'Kijelölés' },
            multiple: false
        }).on('select', function () {
            var attachment = uploader.state().get('selection').first().toJSON();
            bgInput.val(attachment.url).trigger('change');
        }).open();
    });

    $('body').on('click', '.photowooshop-browse-mockup', function (e) {
        e.preventDefault();
        const targetId = $(this).data('target');
        var uploader = wp.media({
            title: 'Mockup háttér kiválasztása',
            button: { text: 'Kijelölés' },
            multiple: false
        }).on('select', function () {
            var attachment = uploader.state().get('selection').first().toJSON();
            $('#' + targetId).val(attachment.url);
        }).open();
    });

    init();
});
