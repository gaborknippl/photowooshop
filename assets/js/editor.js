jQuery(document).ready(function ($) {
    const modal = $('#photowooshop-modal');
    const openBtn = $('#photowooshop-open-editor');
    const closeBtn = $('.photowooshop-close');
    const container = $('#photowooshop-editor-container');

    let cropper = null;
    let currentSlot = null;
    let currentLayout = 'grid-3x2';
    const slotsData = {};

    const LAYOUTS = {
        'grid-3x2': {
            name: '3x2 Rács',
            slots: 6,
            rows: 2,
            cols: 3,
            aspect: 3 / 2
        },
        'grid-2x3': {
            name: '2x3 Rács',
            slots: 6,
            rows: 3,
            cols: 2,
            aspect: 2 / 3
        },
        'grid-1-5': {
            name: '1 Kiemelt + 5 Kicsi',
            slots: 6,
            rows: 3,
            cols: 3,
            aspect: 1,
            customCss: true
        }
    };

    let pendingUploads = 0;
    const serverImageUrls = {};
    let dynamicSlots = null;
    let textSlots = [];
    let shapeSlots = [];
    let previousTexts = {};

    openBtn.on('click', function () {
        initEditor();
        modal.show();
        updateGridStyle();
    });

    closeBtn.on('click', function () {
        modal.hide();
    });

    function initEditor() {
        if (photowooshop_vars.slots_data) {
            try {
                dynamicSlots = JSON.parse(photowooshop_vars.slots_data);
                if (dynamicSlots.length === 0) dynamicSlots = null;
            } catch (e) {
                dynamicSlots = null;
            }
        }

        if (photowooshop_vars.text_slots_data) {
            try {
                textSlots = JSON.parse(photowooshop_vars.text_slots_data);
            } catch (e) { textSlots = []; }
        }

        if (photowooshop_vars.shape_slots_data) {
            try {
                shapeSlots = JSON.parse(photowooshop_vars.shape_slots_data);
            } catch (e) { shapeSlots = []; }
        }

        const slotsCount = dynamicSlots ? dynamicSlots.length : LAYOUTS[currentLayout].slots;
        const mockupKeys = photowooshop_vars.mockups ? Object.keys(photowooshop_vars.mockups).filter(k => photowooshop_vars.mockups[k].url) : [];

        container.html(`
            <div class="editor-header">
                <h2>Montázs Tervező</h2>
                <p>Töltsd fel a fotóidat és szabd testre a montázst!</p>
            </div>
            
            <div class="montage-grid-wrapper">
                <div id="mockup-container" style="position:relative; margin:0 auto; width:100%; height:100%;">
                    <div id="montage-grid" class="montage-grid ${dynamicSlots ? 'dynamic-tpl' : currentLayout}">
                        ${renderSlots(slotsCount)}
                    </div>
                </div>
            </div>

            <div class="editor-scrollable-controls">
                ${!dynamicSlots ? `
                <div class="layout-selector">
                    ${Object.keys(LAYOUTS).map(key => `
                        <div class="layout-option ${key === currentLayout ? 'active' : ''}" data-layout="${key}">
                            ${LAYOUTS[key].name}
                        </div>
                    `).join('')}
                </div>` : ''}

                ${mockupKeys.length > 0 ? `
                    <div class="mockup-selector">
                        <div class="mockup-option active" data-index="original" data-url="${photowooshop_vars.bg_url}">Eredeti</div>
                        ${mockupKeys.map(k => `
                            <div class="mockup-option" data-index="${k}" data-url="${photowooshop_vars.mockups[k].url}">Mockup ${k.replace('m', '')}</div>
                        `).join('')}
                    </div>
                ` : ''}
                
                <div class="extra-customization">
                    ${textSlots.length > 0 ? textSlots.map((slot, i) => `
                        <div class="field-row">
                            <label>Szöveg ${i + 1}:</label>
                            ${slot.multiline ?
                `<textarea class="photowooshop-text-field tpl-text-input" data-index="${i}" placeholder="Írd ide a szöveget..." rows="2" style="width:100%; resize:vertical; margin-top:5px;">${previousTexts[i] || ''}</textarea>` :
                `<input type="text" class="photowooshop-text-field tpl-text-input" data-index="${i}" placeholder="Írd ide a szöveget..." value="${previousTexts[i] || ''}">`
            }
                        </div>
                    `).join('') : ''}
                    
                    ${photowooshop_vars.audio_enabled ? `
                        <div class="field-row">
                            <label>Hangfájl feltöltése:</label>
                            <input type="file" id="photowooshop-audio-input" accept="audio/*">
                        </div>
                    ` : ''}
                </div>

                <div class="editor-controls">
                    <div id="photowooshop-preview-status" style="margin-bottom: 10px; font-weight: bold; color: #6200ee;"></div>
                    <button type="button" id="photowooshop-save" class="photowooshop-btn" disabled>Szerkesztés befejezése</button>
                    <div id="photowooshop-upload-msg" style="display:none; margin-top:10px; font-size:12px; color:#666;">Folyamatban...</div>
                </div>
            </div>

            <input type="file" id="photowooshop-upload" style="display:none;" accept="image/*">
            
            <div id="photowooshop-cropper-modal" class="cropping-modal">
                <div class="cropper-container-inner">
                    <img id="photowooshop-crop-image" src="">
                </div>
                <div class="cropping-toolbar">
                    <button type="button" id="crop-cancel" class="photowooshop-btn" style="background:#555; width:auto;">Mégse</button>
                    <button type="button" id="crop-confirm" class="photowooshop-btn" style="width:auto;">Mentés és Vágás</button>
                </div>
            </div>
        `);

        bindEvents();
        renderShapeLayers();
        checkCompletion();

        // Use ResizeObserver for robust layout handling
        const wrapper = document.querySelector('.montage-grid-wrapper');
        if (wrapper) {
            const ro = new ResizeObserver(entries => {
                for (let entry of entries) {
                    updateGridStyle();
                }
            });
            ro.observe(wrapper);
            // Also observe the modal content to catch flex changes
            const content = document.querySelector('.photowooshop-modal-content');
            if (content) ro.observe(content);
        }
    }

    function renderSlots(count) {
        let html = '';
        for (let i = 1; i <= count; i++) {
            const imgSrc = serverImageUrls[i] || slotsData[i];
            const hasImg = !!imgSrc;
            const content = hasImg ? `<img src="${imgSrc}" style="width:100%; height:100%; object-fit:cover;">` : `<span class="slot-label">${i}. hely</span>`;
            const slotRadiusCss = dynamicSlots ? getImageSlotBorderRadiusCss(dynamicSlots[i - 1], 4) : '4px';

            html += `
                <div class="montage-slot slot-${i} ${hasImg ? 'has-image' : ''}" data-slot="${i}" style="border-radius:${slotRadiusCss};">
                    ${content}
                    ${hasImg ? `
                        <div class="slot-actions">
                            <span class="action-btn re-crop" title="Vágás újra">✂️</span>
                            <span class="action-btn change-img" title="Másik kép">🔄</span>
                        </div>
                    ` : ''}
                </div>`;
        }
        return html;
    }

    function normalizeShapeSlot(slot) {
        return Object.assign({
            type: 'rect',
            x: 50,
            y: 50,
            w: 40,
            h: 20,
            color: '#000000',
            opacity: 0.45,
            radius: 10,
            radii: { tl: 10, tr: 10, br: 10, bl: 10 },
            z: 30
        }, slot || {});
    }

    function normalizeTextSlot(slot) {
        return Object.assign({
            x: 50,
            y: 50,
            max_w: 80,
            color: '#ffffff',
            multiline: false,
            font_family: 'hello honey',
            font_size: 50,
            z: 50
        }, slot || {});
    }

    function getImageSlotLayer(slot, fallback = 10) {
        const z = parseInt(slot && slot.z, 10);
        return Number.isNaN(z) ? fallback : z;
    }

    function getImageSlotCornerRadii(slot, fallback = 0) {
        const base = Math.max(0, parseFloat(slot && slot.radius));
        const safeBase = Number.isNaN(base) ? fallback : base;
        const source = (slot && slot.radii && typeof slot.radii === 'object') ? slot.radii : {};

        const tl = Math.max(0, parseFloat(source.tl));
        const tr = Math.max(0, parseFloat(source.tr));
        const br = Math.max(0, parseFloat(source.br));
        const bl = Math.max(0, parseFloat(source.bl));

        return {
            tl: Number.isNaN(tl) ? safeBase : tl,
            tr: Number.isNaN(tr) ? safeBase : tr,
            br: Number.isNaN(br) ? safeBase : br,
            bl: Number.isNaN(bl) ? safeBase : bl
        };
    }

    function getImageSlotBorderRadiusCss(slot, fallback = 0) {
        const radii = getImageSlotCornerRadii(slot, fallback);
        return `${radii.tl}px ${radii.tr}px ${radii.br}px ${radii.bl}px`;
    }

    function getShapeCornerRadii(slot, fallback = 0) {
        const base = Math.max(0, parseFloat(slot && slot.radius));
        const safeBase = Number.isNaN(base) ? fallback : base;
        const source = (slot && slot.radii && typeof slot.radii === 'object') ? slot.radii : {};

        const tl = Math.max(0, parseFloat(source.tl));
        const tr = Math.max(0, parseFloat(source.tr));
        const br = Math.max(0, parseFloat(source.br));
        const bl = Math.max(0, parseFloat(source.bl));

        return {
            tl: Number.isNaN(tl) ? safeBase : tl,
            tr: Number.isNaN(tr) ? safeBase : tr,
            br: Number.isNaN(br) ? safeBase : br,
            bl: Number.isNaN(bl) ? safeBase : bl
        };
    }

    function getShapeBorderRadiusCss(slot, fallback = 0) {
        const radii = getShapeCornerRadii(slot, fallback);
        return `${radii.tl}px ${radii.tr}px ${radii.br}px ${radii.bl}px`;
    }

    function renderShapeLayers() {
        const grid = $('#montage-grid');
        grid.find('.photowooshop-shape-layer').remove();

        if (!Array.isArray(shapeSlots) || shapeSlots.length === 0) {
            return;
        }

        shapeSlots.forEach((rawSlot, idx) => {
            const slot = normalizeShapeSlot(rawSlot);
            shapeSlots[idx] = slot;

            const el = $('<div class="photowooshop-shape-layer"></div>');
            applyShapeLayerStyles(el, slot);
            grid.append(el);
        });
    }

    function applyShapeLayerStyles(el, slot) {
        const safeOpacity = Math.max(0, Math.min(1, parseFloat(slot.opacity) || 0));
        const safeZ = parseInt(slot.z, 10);

        el.css({
            position: 'absolute',
            left: slot.x + '%',
            top: slot.y + '%',
            width: slot.w + '%',
            height: slot.h + '%',
            transform: 'translate(-50%, -50%)',
            background: slot.color || '#000000',
            opacity: safeOpacity,
            borderRadius: slot.type === 'circle' ? '9999px' : getShapeBorderRadiusCss(slot, 10),
            zIndex: Number.isNaN(safeZ) ? 12 : safeZ,
            pointerEvents: 'none'
        });
    }

    function updateGridStyle() {
        const grid = $('#montage-grid');
        const container = $('#mockup-container');
        const wrapper = $('.montage-grid-wrapper');
        const rootContainer = $('#photowooshop-editor-container');
        if (!grid.length || !wrapper.length || !rootContainer.length) return;

        const availableW = wrapper.width();
        const availableH = wrapper.height();

        const activeMockupIdx = $('.mockup-option.active').data('index') || 'original';
        const isMockup = activeMockupIdx !== 'original';

        if (dynamicSlots && photowooshop_vars.bg_url) {
            const templateBgImg = new Image();
            templateBgImg.src = photowooshop_vars.bg_url;

            const processStyle = () => {
                const templateAspect = templateBgImg.width / templateBgImg.height;

                if (isMockup) {
                    const mk = photowooshop_vars.mockups[activeMockupIdx];
                    const mkImg = new Image();
                    mkImg.src = mk.url;
                    if (mkImg.complete) applyScaling(grid, container, availableW, availableH, templateAspect, true, mkImg, mk);
                    else mkImg.onload = () => applyScaling(grid, container, availableW, availableH, templateAspect, true, mkImg, mk);
                } else {
                    applyScaling(grid, container, availableW, availableH, templateAspect);
                }
            };

            if (templateBgImg.complete) processStyle();
            else templateBgImg.onload = processStyle;
        } else {
            const layout = LAYOUTS[currentLayout];
            applyScaling(grid, container, availableW, availableH, layout.aspect);
        }
    }

    function applyScaling(grid, container, availableW, availableH, templateAspect, isMockup = false, mkImg = null, mkData = null) {
        let finalW, finalH;

        if (availableH <= 0) availableH = 1;
        if (availableW <= 0) availableW = 1;

        let aspect = isMockup && mkImg ? (mkImg.width / mkImg.height) : templateAspect;

        if (availableW / availableH > aspect) {
            finalH = availableH;
            finalW = finalH * aspect;
        } else {
            finalW = availableW;
            finalH = finalW / aspect;
        }

        if (isMockup) {
            container.css({
                'width': finalW + 'px',
                'height': finalH + 'px',
                'background-image': `url(${mkData.url})`,
                'background-size': '100% 100%'
            });

            const srcW = finalW;
            const srcH = srcW / templateAspect;

            grid.css({
                'width': srcW + 'px',
                'height': srcH + 'px',
                'display': 'block',
                'position': 'absolute',
                'top': 0,
                'left': 0,
                'background-image': `url(${photowooshop_vars.bg_url})`,
                'background-size': '100% 100%',
                'transform-origin': '0 0',
                'z-index': 10
            });

            const tl = mkData.tl.split(',').map(Number);
            const tr = mkData.tr.split(',').map(Number);
            const bl = mkData.bl.split(',').map(Number);
            const br = mkData.br.split(',').map(Number);

            const dst = [
                [tl[0] / 100 * finalW, tl[1] / 100 * finalH],
                [tr[0] / 100 * finalW, tr[1] / 100 * finalH],
                [br[0] / 100 * finalW, br[1] / 100 * finalH],
                [bl[0] / 100 * finalW, bl[1] / 100 * finalH]
            ];
            const src = [
                [0, 0], [srcW, 0], [srcW, srcH], [0, srcH]
            ];

            grid.css('transform', createMatrix3d(src, dst));

        } else {
            container.css({
                'width': finalW + 'px',
                'height': finalH + 'px',
                'background-image': 'none'
            });

            grid.css({
                'width': finalW + 'px',
                'height': finalH + 'px',
                'display': dynamicSlots ? 'block' : 'grid',
                'position': 'relative',
                'top': 'auto',
                'left': 'auto',
                'background-image': photowooshop_vars.bg_url ? `url(${photowooshop_vars.bg_url})` : 'none',
                'background-size': dynamicSlots ? '100% 100%' : 'cover',
                'transform': 'none'
            });

            if (!dynamicSlots) {
                const layout = LAYOUTS[currentLayout];
                grid.css({
                    'grid-template-columns': `repeat(${layout.cols}, 1fr)`,
                    'grid-template-rows': `repeat(${layout.rows}, 1fr)`
                });
            }
        }

        if (dynamicSlots) {
            dynamicSlots.forEach((slot, idx) => {
                $(`.montage-slot.slot-${idx + 1}`).css({
                    'position': 'absolute',
                    'left': slot.x + '%',
                    'top': slot.y + '%',
                    'width': slot.w + '%',
                    'height': slot.h + '%',
                    'border-radius': getImageSlotBorderRadiusCss(slot, 4),
                    'z-index': getImageSlotLayer(slot, 10)
                });
            });
        } else {
            if (currentLayout === 'grid-1-5') {
                $('.slot-1').css({ 'grid-column': 'span 2', 'grid-row': 'span 2' });
            } else {
                $('.montage-slot').css({ 'grid-column': '', 'grid-row': '' });
            }
        }

        renderShapeLayers();
        updateLiveText();
    }

    function createMatrix3d(src, dst) {
        let a = [];
        for (let i = 0; i < 4; i++) {
            let sx = src[i][0], sy = src[i][1], dx = dst[i][0], dy = dst[i][1];
            a.push([sx, sy, 1, 0, 0, 0, -dx * sx, -dx * sy, dx]);
            a.push([0, 0, 0, sx, sy, 1, -dy * sx, -dy * sy, dy]);
        }

        for (let i = 0; i < 8; i++) {
            let max = i;
            for (let j = i + 1; j < 8; j++) if (Math.abs(a[j][i]) > Math.abs(a[max][i])) max = j;
            let t = a[i]; a[i] = a[max]; a[max] = t;
            if (a[i][i] === 0) continue;
            for (let j = i + 1; j < 8; j++) {
                let f = a[j][i] / a[i][i];
                for (let k = i; k < 9; k++) a[j][k] -= a[i][k] * f;
            }
        }
        let res = new Array(8).fill(0);
        for (let i = 7; i >= 0; i--) {
            if (a[i][i] === 0) continue;
            let sum = a[i][8];
            for (let j = i + 1; j < 8; j++) sum -= a[i][j] * res[j];
            res[i] = sum / a[i][i];
        }

        let H = [
            res[0], res[3], 0, res[6],
            res[1], res[4], 0, res[7],
            0, 0, 1, 0,
            res[2], res[5], 0, 1
        ];
        return "matrix3d(" + H.map(v => v.toFixed(6)).join(",") + ")";
    }

    function updateLiveText(changedIdx = -1) {
        const grid = $('#montage-grid');
        const gridWidth = grid.width();

        let anyExceeded = false;

        $('.tpl-text-input').each(function () {
            const idx = $(this).data('index');
            const text = $(this).val();
            const slot = textSlots[idx];
            if (!slot) return;
            const safeSlot = normalizeTextSlot(slot);
            textSlots[idx] = safeSlot;

            let previewEl = $(`#photowooshop-live-text-preview-${idx}`);

            if (!text) {
                if (previewEl.length) previewEl.hide();
                return;
            }

            if (!previewEl.length) {
                previewEl = $(`<div id="photowooshop-live-text-preview-${idx}" class="live-text-preview"></div>`).appendTo(grid);
            }

            // Canvas is 1200px wide based. Admin font size is relative.
            const scale = gridWidth / 1200;
            const fontSize = Math.max(10, safeSlot.font_size * scale * 2);
            const maxPxW = (safeSlot.max_w / 100) * gridWidth;

            previewEl.text(text).css({
                'position': 'absolute',
                'left': safeSlot.x + '%',
                'top': safeSlot.y + '%',
                'transform': 'translate(-50%, -50%)',
                'color': safeSlot.color || 'white',
                'font-family': `"${safeSlot.font_family}", cursive, sans-serif`,
                'font-weight': 'normal',
                'font-size': fontSize + 'px',
                'text-align': 'center',
                'max-width': maxPxW + 'px',
                'width': 'max-content',
                'white-space': safeSlot.multiline ? 'pre-wrap' : 'nowrap',
                'word-break': safeSlot.multiline ? 'break-word' : 'normal',
                'z-index': parseInt(safeSlot.z, 10) || 50,
                'pointer-events': 'none',
                'line-height': '1'
            }).show();

            if (changedIdx === idx) {
                if (!safeSlot.multiline && previewEl[0].scrollWidth > Math.ceil(maxPxW)) {
                    anyExceeded = true;
                }
            }
        });

        return anyExceeded;
    }

    // Removed old resize handler, now using ResizeObserver in initEditor

    function bindEvents() {
        if (!dynamicSlots) {
            $('.layout-option').on('click', function () {
                currentLayout = $(this).data('layout');
                $('.layout-option').removeClass('active');
                $(this).addClass('active');
                $('#montage-grid').attr('class', `montage-grid ${currentLayout}`).html(renderSlots(LAYOUTS[currentLayout].slots));
                renderShapeLayers();
                updateGridStyle();
                bindSlotEvents();
                checkCompletion();
            });
        }

        bindSlotEvents();

        $('#photowooshop-upload').off('change').on('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (event) {
                    startCropping(event.target.result);
                };
                reader.readAsDataURL(file);
            }
        });


        $('.extra-customization').on('input', '.tpl-text-input', function () {
            const idx = $(this).data('index');
            const exceeded = updateLiveText(idx);
            const slot = textSlots[idx];

            if (exceeded && slot && !slot.multiline) {
                $(this).val(previousTexts[idx] || '');
                updateLiveText(); // Revert preview
            } else {
                previousTexts[idx] = $(this).val();
            }
        });

        $('#photowooshop-save').off('click').on('click', function (e) {
            e.preventDefault();
            if (pendingUploads > 0) {
                alert('Kérlek várj, a képek feltöltése még folyamatban van...');
                return;
            }
            $(this).prop('disabled', true).text('Mentés...');
            generateFinalImage();
        });

        $('.mockup-option').on('click', function () {
            $('.mockup-option').removeClass('active');
            $(this).addClass('active');
            updateGridStyle();
        });
    }

    function bindSlotEvents() {
        $('.montage-slot:not(.has-image)').off('click').on('click', function () {
            currentSlot = $(this).data('slot');
            $('#photowooshop-upload').click();
        });

        $('.action-btn.change-img').off('click').on('click', function (e) {
            e.stopPropagation();
            currentSlot = $(this).closest('.montage-slot').data('slot');
            $('#photowooshop-upload').click();
        });

        $('.action-btn.re-crop').off('click').on('click', function (e) {
            e.stopPropagation();
            currentSlot = $(this).closest('.montage-slot').data('slot');
            $('#photowooshop-upload').click();
        });
    }

    function startCropping(imageSrc) {
        const cropModal = $('#photowooshop-cropper-modal');
        const cropImage = $('#photowooshop-crop-image');

        cropImage.attr('src', imageSrc);
        cropModal.css('display', 'flex');

        if (cropper) cropper.destroy();

        let slotAspect = 1;
        if (dynamicSlots) {
            const slot = dynamicSlots[currentSlot - 1];
            const gridW = $('#montage-grid').width();
            const gridH = $('#montage-grid').height();
            const pixelW = (slot.w / 100) * gridW;
            const pixelH = (slot.h / 100) * gridH;
            slotAspect = pixelW / pixelH;
        } else {
            const layout = LAYOUTS[currentLayout];
            if (currentLayout === 'grid-3x2') slotAspect = 1;
            else if (currentLayout === 'grid-2x3') slotAspect = 1;
            else if (currentLayout === 'grid-1-5') slotAspect = 1;
        }

        cropper = new Cropper(cropImage[0], {
            aspectRatio: slotAspect,
            viewMode: 1,
            autoCropArea: 1,
            dragMode: 'move',
            restore: false,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false
        });

        $('#crop-confirm').off('click').on('click', function () {
            const canvas = cropper.getCroppedCanvas({ width: 1200, height: 1200 / slotAspect });
            const croppedData = canvas.toDataURL('image/jpeg', 0.9);

            slotsData[currentSlot] = croppedData;
            uploadIndividualImage(croppedData, currentSlot);

            const slotEl = $(`.montage-slot[data-slot="${currentSlot}"]`);
            slotEl.addClass('has-image').html(`
                <img src="${croppedData}" style="width:100%; height:100%; object-fit:cover;">
                <div class="slot-actions">
                    <span class="action-btn re-crop" title="Vágás újra">✂️</span>
                    <span class="action-btn change-img" title="Másik kép">🔄</span>
                </div>
            `);
            bindSlotEvents();

            cropModal.hide();
            checkCompletion();
        });

        $('#crop-cancel').off('click').on('click', function () {
            cropModal.hide();
        });
    }

    function uploadIndividualImage(base64, slotIdx) {
        pendingUploads++;
        $('#photowooshop-upload-msg').show();
        const productId = photowooshop_vars.product_id || $('[name="add-to-cart"]').val() || 0;

        $.ajax({
            url: photowooshop_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'photowooshop_save_image',
                nonce: photowooshop_vars.nonce,
                product_id: productId,
                upload_token: photowooshop_vars.upload_token,
                image: base64
            },
            success: function (response) {
                if (response.success) {
                    serverImageUrls[slotIdx] = response.data.url;
                }
            },
            complete: function () {
                pendingUploads--;
                if (pendingUploads === 0) {
                    $('#photowooshop-upload-msg').fadeOut();
                }
            }
        });
    }

    function checkCompletion() {
        const count = dynamicSlots ? dynamicSlots.length : LAYOUTS[currentLayout].slots;
        const filled = Object.keys(slotsData).length;
        $('#photowooshop-preview-status').text(`${filled} / ${count} fotó feltöltve`);
        if (filled === count) {
            $('#photowooshop-save').prop('disabled', false).addClass('pulsing').text('Szerkesztés befejezése');
        } else {
            $('#photowooshop-save').prop('disabled', true).removeClass('pulsing').text('Szerkesztés befejezése');
        }
    }

    function generateFinalImage() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        function normalizeCornerRadii(radii, w, h) {
            const out = {
                tl: Math.max(0, radii.tl || 0),
                tr: Math.max(0, radii.tr || 0),
                br: Math.max(0, radii.br || 0),
                bl: Math.max(0, radii.bl || 0)
            };

            const factors = [1];
            if (out.tl + out.tr > 0) factors.push(w / (out.tl + out.tr));
            if (out.bl + out.br > 0) factors.push(w / (out.bl + out.br));
            if (out.tl + out.bl > 0) factors.push(h / (out.tl + out.bl));
            if (out.tr + out.br > 0) factors.push(h / (out.tr + out.br));

            const scale = Math.min.apply(null, factors);
            if (scale < 1) {
                out.tl *= scale;
                out.tr *= scale;
                out.br *= scale;
                out.bl *= scale;
            }

            return out;
        }

        function drawRoundedRectPath(context, x, y, w, h, radii) {
            const r = normalizeCornerRadii(radii, w, h);
            context.beginPath();
            context.moveTo(x + r.tl, y);
            context.lineTo(x + w - r.tr, y);
            context.quadraticCurveTo(x + w, y, x + w, y + r.tr);
            context.lineTo(x + w, y + h - r.br);
            context.quadraticCurveTo(x + w, y + h, x + w - r.br, y + h);
            context.lineTo(x + r.bl, y + h);
            context.quadraticCurveTo(x, y + h, x, y + h - r.bl);
            context.lineTo(x, y + r.tl);
            context.quadraticCurveTo(x, y, x + r.tl, y);
            context.closePath();
        }

        function drawShapeLayer(slot) {
            const x = (slot.x / 100) * canvas.width;
            const y = (slot.y / 100) * canvas.height;
            const w = (slot.w / 100) * canvas.width;
            const h = (slot.h / 100) * canvas.height;
            const left = x - (w / 2);
            const top = y - (h / 2);
            const opacity = Math.max(0, Math.min(1, parseFloat(slot.opacity) || 0));

            ctx.save();
            ctx.globalAlpha = opacity;
            ctx.fillStyle = slot.color || '#000000';

            if (slot.type === 'circle') {
                ctx.beginPath();
                ctx.ellipse(x, y, w / 2, h / 2, 0, 0, Math.PI * 2);
                ctx.fill();
            } else {
                const rawRadii = getShapeCornerRadii(slot, 10);
                const scaledRadii = {
                    tl: rawRadii.tl,
                    tr: rawRadii.tr,
                    br: rawRadii.br,
                    bl: rawRadii.bl
                };
                drawRoundedRectPath(ctx, left, top, w, h, scaledRadii);
                ctx.fill();
            }

            ctx.restore();
        }

        function drawTextLayer(slot, text) {
            ctx.fillStyle = slot.color || 'white';
            ctx.font = `${Math.max(10, slot.font_size * 2)}px "${slot.font_family}", cursive, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            const tx = (slot.x / 100) * canvas.width;
            const ty = (slot.y / 100) * canvas.height;
            const maxW = (slot.max_w / 100) * canvas.width;

            if (slot.multiline) {
                const lines = text.split('\n');
                const wrappedLines = [];
                const lineHeight = Math.max(10, slot.font_size * 2) * 1.1;

                for (let i = 0; i < lines.length; i++) {
                    const words = lines[i].split(' ');
                    let line = '';

                    for (let n = 0; n < words.length; n++) {
                        const testLine = line + words[n] + ' ';
                        const metrics = ctx.measureText(testLine);
                        if (metrics.width > maxW && n > 0) {
                            wrappedLines.push(line);
                            line = words[n] + ' ';
                        } else {
                            line = testLine;
                        }
                    }
                    wrappedLines.push(line);
                }

                const totalHeight = wrappedLines.length * lineHeight;
                let currentY = ty - (totalHeight / 2) + (lineHeight / 2);

                wrappedLines.forEach(l => {
                    ctx.fillText(l.trim(), tx, currentY);
                    currentY += lineHeight;
                });
            } else {
                ctx.fillText(text.trim(), tx, ty, maxW);
            }
        }

        let canvasW = 1200;
        let canvasH = 1200;

        const drawProcess = (bgImg = null) => {
            if (bgImg) {
                canvasW = 1200;
                canvasH = 1200 / (bgImg.width / bgImg.height);
            } else if (!dynamicSlots) {
                canvasH = 1200 / LAYOUTS[currentLayout].aspect;
            }

            canvas.width = canvasW;
            canvas.height = canvasH;

            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            if (bgImg) {
                ctx.drawImage(bgImg, 0, 0, canvas.width, canvas.height);
            }

            const slotsCount = dynamicSlots ? dynamicSlots.length : LAYOUTS[currentLayout].slots;
            const imageLayerPromises = [];

            for (let i = 1; i <= slotsCount; i++) {
                imageLayerPromises.push(new Promise((resolve) => {
                    const img = new Image();
                    img.src = slotsData[i];
                    img.onload = function () {
                        let x, y, w, h;
                        let z = 10;
                        let radii = { tl: 0, tr: 0, br: 0, bl: 0 };

                        if (dynamicSlots) {
                            const slot = dynamicSlots[i - 1];
                            x = (slot.x / 100) * canvas.width;
                            y = (slot.y / 100) * canvas.height;
                            w = (slot.w / 100) * canvas.width;
                            h = (slot.h / 100) * canvas.height;
                            z = getImageSlotLayer(slot, 10);
                            radii = getImageSlotCornerRadii(slot, 0);
                        } else {
                            const layout = LAYOUTS[currentLayout];
                            w = canvas.width / layout.cols;
                            h = canvas.height / layout.rows;
                            x = ((i - 1) % layout.cols) * w;
                            y = Math.floor((i - 1) / layout.cols) * h;
                        }

                        resolve({ kind: 'image', z: z, img: img, x: x, y: y, w: w, h: h, radii: radii });
                    };
                    img.onerror = function () {
                        resolve(null);
                    };
                }));
            }

            Promise.all(imageLayerPromises).then((imageLayers) => {
                const allLayers = [];

                imageLayers.forEach((layer) => {
                    if (layer) {
                        allLayers.push(layer);
                    }
                });

                shapeSlots.forEach((rawSlot) => {
                    const slot = normalizeShapeSlot(rawSlot);
                    allLayers.push({ kind: 'shape', z: parseInt(slot.z, 10) || 20, slot: slot });
                });

                textSlots.forEach((rawSlot, idx) => {
                    const slot = normalizeTextSlot(rawSlot);
                    const textInput = $(`.tpl-text-input[data-index="${idx}"]`);
                    if (!textInput.length) return;
                    const text = textInput.val();
                    if (!text) return;
                    allLayers.push({ kind: 'text', z: parseInt(slot.z, 10) || 50, slot: slot, text: text });
                });

                allLayers.sort((a, b) => a.z - b.z);

                const gridWidth = $('#montage-grid').width() || canvas.width;
                const gridHeight = $('#montage-grid').height() || canvas.height;
                const radiusScaleX = canvas.width / gridWidth;
                const radiusScaleY = canvas.height / gridHeight;
                const radiusScale = Math.min(radiusScaleX, radiusScaleY);

                allLayers.forEach((layer) => {
                    if (layer.kind === 'image') {
                        const scaledRadii = {
                            tl: Math.max(0, (layer.radii && layer.radii.tl ? layer.radii.tl : 0) * radiusScale),
                            tr: Math.max(0, (layer.radii && layer.radii.tr ? layer.radii.tr : 0) * radiusScale),
                            br: Math.max(0, (layer.radii && layer.radii.br ? layer.radii.br : 0) * radiusScale),
                            bl: Math.max(0, (layer.radii && layer.radii.bl ? layer.radii.bl : 0) * radiusScale)
                        };

                        if (scaledRadii.tl > 0 || scaledRadii.tr > 0 || scaledRadii.br > 0 || scaledRadii.bl > 0) {
                            ctx.save();
                            drawRoundedRectPath(ctx, layer.x, layer.y, layer.w, layer.h, scaledRadii);
                            ctx.clip();
                            ctx.drawImage(layer.img, layer.x, layer.y, layer.w, layer.h);
                            ctx.restore();
                        } else {
                            ctx.drawImage(layer.img, layer.x, layer.y, layer.w, layer.h);
                        }
                    } else if (layer.kind === 'shape') {
                        drawShapeLayer(layer.slot);
                    } else if (layer.kind === 'text') {
                        drawTextLayer(layer.slot, layer.text);
                    }
                });

                const finalBase64 = canvas.toDataURL('image/jpeg', 0.9);

                $('#photowooshop-upload-msg').show().text('Montázs véglegesítése...');

                $.ajax({
                    url: photowooshop_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'photowooshop_save_image',
                        nonce: photowooshop_vars.nonce,
                        product_id: photowooshop_vars.product_id || $('[name="add-to-cart"]').val() || 0,
                        upload_token: photowooshop_vars.upload_token,
                        image: finalBase64
                    },
                    success: function (response) {
                        if (response.success) {
                            const finalMontageUrl = response.data.url;
                            const montageImg = new Image();
                            montageImg.crossOrigin = 'anonymous';
                            montageImg.src = finalMontageUrl;
                            montageImg.onload = function () {
                                generateMockups(finalMontageUrl, montageImg);
                            };
                        } else {
                            const details = response && response.data ? (' Részlet: ' + response.data) : '';
                            alert('Hiba történt a kép mentésekor. Kérlek próbáld újra!' + details);
                            $('#photowooshop-save').prop('disabled', false).text('Szerkesztés befejezése');
                        }
                    },
                    error: function () {
                        alert('Hálózati hiba. Kérlek próbáld újra!');
                        $('#photowooshop-save').prop('disabled', false).text('Szerkesztés befejezése');
                    }
                });
            });
        };

        const currentBgUrl = $('.mockup-option.active').data('url') || photowooshop_vars.bg_url;

        if (currentBgUrl) {
            const bgImg = new Image();
            bgImg.crossOrigin = "anonymous";
            bgImg.src = currentBgUrl;
            bgImg.onload = () => drawProcess(bgImg);
            bgImg.onerror = () => drawProcess();
        } else {
            drawProcess();
        }
    }

    function generateMockups(montageUrl, montageImg) {
        const mockupKeys = photowooshop_vars.mockups ? Object.keys(photowooshop_vars.mockups) : [];
        const results = [montageUrl];
        let processed = 0;

        function processNext() {
            if (processed >= mockupKeys.length) {
                finishAndSync(results);
                return;
            }

            const m = photowooshop_vars.mockups[mockupKeys[processed]];
            if (!m || !m.url) {
                processed++;
                processNext();
                return;
            }

            $('#photowooshop-upload-msg').text(`Mockup ${processed + 1} generálása...`);

            const bg = new Image();
            bg.crossOrigin = "anonymous";
            bg.src = m.url;
            bg.onload = function () {
                const canvas = document.createElement('canvas');
                canvas.width = bg.width;
                canvas.height = bg.height;
                const ctx = canvas.getContext('2d');

                ctx.drawImage(bg, 0, 0);

                // Use 4 points for perspective warp
                const pts = {
                    tl: m.tl.split(',').map(v => parseFloat(v) / 100 * canvas.width),
                    tr: m.tr.split(',').map(v => parseFloat(v) / 100 * canvas.width),
                    bl: m.bl.split(',').map(v => parseFloat(v) / 100 * canvas.width),
                    br: m.br.split(',').map(v => parseFloat(v) / 100 * canvas.width)
                };

                // Normalizing Y coordinates (they were mapped to W in my splitter above by mistake, fixing)
                pts.tl[1] = parseFloat(m.tl.split(',')[1]) / 100 * canvas.height;
                pts.tr[1] = parseFloat(m.tr.split(',')[1]) / 100 * canvas.height;
                pts.bl[1] = parseFloat(m.bl.split(',')[1]) / 100 * canvas.height;
                pts.br[1] = parseFloat(m.br.split(',')[1]) / 100 * canvas.height;

                drawPerspective(ctx, montageImg, pts.tl, pts.tr, pts.br, pts.bl);

                const base64 = canvas.toDataURL('image/jpeg', 0.9);
                $.ajax({
                    url: photowooshop_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'photowooshop_save_image',
                        nonce: photowooshop_vars.nonce,
                        product_id: photowooshop_vars.product_id || $('[name="add-to-cart"]').val() || 0,
                        upload_token: photowooshop_vars.upload_token,
                        image: base64
                    },
                    success: function (response) {
                        if (response.success) {
                            results.push(response.data.url);
                        }
                        processed++;
                        processNext();
                    },
                    error: function () {
                        processed++;
                        processNext();
                    }
                });
            };
            bg.onerror = function () {
                processed++;
                processNext();
            };
        }

        processNext();
    }

    // Perspective Warp Engine (Mesh Subdivision)
    function drawPerspective(ctx, img, tl, tr, br, bl) {
        const steps = 20;
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
        const x = (1 - u) * (1 - v) * tl[0] + u * (1 - v) * tr[0] + u * v * br[0] + (1 - u) * v * bl[0];
        const y = (1 - u) * (1 - v) * tl[1] + u * (1 - v) * tr[1] + u * v * br[1] + (1 - u) * v * bl[1];
        return [x, y];
    }

    function drawTriangle(ctx, img, sx1, sy1, sx2, sy2, sx3, sy3, dx1, dy1, dx2, dy2, dx3, dy3) {
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(dx1, dy1);
        ctx.lineTo(dx2, dy2);
        ctx.lineTo(dx3, dy3);
        ctx.closePath();
        ctx.clip();

        const denom = sx1 * (sy2 - sy3) - sx2 * sy1 + sx2 * sy3 + sx3 * sy1 - sx3 * sy2;
        if (Math.abs(denom) < 0.0001) { ctx.restore(); return; }

        const m11 = -(sy1 * (dx2 - dx3) - sy2 * dx1 + sy2 * dx3 + sy3 * dx1 - sy3 * dx2) / denom;
        const m12 = (sy1 * (dy2 - dy3) - sy2 * dy1 + sy2 * dy3 + sy3 * dy1 - sy3 * dy2) / denom;
        const m21 = (sx1 * (dx2 - dx3) - sx2 * dx1 + sx2 * dx3 + sx3 * dx1 - sx3 * dx2) / denom;
        const m22 = -(sx1 * (dy2 - dy3) - sx2 * dy1 + sx2 * dy3 + sx3 * dy1 - sx3 * dy2) / denom;
        const dx = (sx1 * (sy2 * dx3 - sy3 * dx2) + sy1 * (sx3 * dx2 - sx2 * dx3) + (sx2 * sy3 - sx3 * sy2) * dx1) / denom;
        const dy = (sx1 * (sy2 * dy3 - sy3 * dy2) + sy1 * (sx3 * dy2 - sx2 * dy3) + (sx2 * sy3 - sx3 * sy2) * dy1) / denom;

        ctx.setTransform(m11, m12, m21, m22, dx, dy);
        ctx.drawImage(img, 0, 0);
        ctx.restore();
    }

    function finishAndSync(mockupUrls) {
        const audioInput = $('#photowooshop-audio-input');
        if (audioInput.length && audioInput[0].files.length) {
            $('#photowooshop-upload-msg').text('Hangfájl feltöltése...');
            const formData = new FormData();
            formData.append('action', 'photowooshop_upload_audio');
            formData.append('nonce', photowooshop_vars.nonce);
            formData.append('product_id', photowooshop_vars.product_id || $('[name="add-to-cart"]').val() || 0);
            formData.append('upload_token', photowooshop_vars.upload_token);
            formData.append('audio', audioInput[0].files[0]);

            $.ajax({
                url: photowooshop_vars.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (audioResponse) {
                    if (audioResponse.success) {
                        syncDataAndFinish(mockupUrls, audioResponse.data.url);
                    } else {
                        alert('Hiba a hangfájl feltöltésekor: ' + audioResponse.data);
                        $('#photowooshop-save').prop('disabled', false).text('Szerkesztés befejezése');
                    }
                }
            });
        } else {
            syncDataAndFinish(mockupUrls, '');
        }
    }

    function syncDataAndFinish(mockupUrls, audioUrl) {
        $('#photowooshop-upload-msg').show().text('Adatok szinkronizálása...');

        const productId = $('[name="add-to-cart"]').val() || $('.single_add_to_cart_button').val() || 0;
        const textValues = [];
        $('.tpl-text-input').each(function () {
            const val = $(this).val();
            if (val) textValues.push(val);
        });
        const text = textValues.join(' | ');
        const mainMontage = mockupUrls[0];

        $.ajax({
            url: photowooshop_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'photowooshop_sync_session',
                nonce: photowooshop_vars.nonce,
                product_id: productId,
                upload_token: photowooshop_vars.upload_token,
                montage_url: mainMontage,
                individual_images: JSON.stringify(serverImageUrls),
                text: text,
                audio_url: audioUrl,
                mockups: JSON.stringify(mockupUrls)
            },
            success: function (response) {
                applyChangesToProductPage(mockupUrls, audioUrl);
            },
            error: function () {
                applyChangesToProductPage(mockupUrls, audioUrl);
            }
        });
    }

    function applyChangesToProductPage(mockupUrls, audioUrl) {
        // Explicitly capture current text values before closing
        $('.tpl-text-input').each(function () {
            const idx = $(this).data('index');
            previousTexts[idx] = $(this).val();
        });

        modal.hide();
        const imageUrl = mockupUrls[0];

        const mainImageWrapper = $('.woocommerce-product-gallery__image').first();
        const mainImage = mainImageWrapper.find('img').first();

        if (mainImage.length) {
            mainImage.attr('src', imageUrl);
            mainImage.attr('srcset', '');
            mainImage.removeAttr('sizes');
            mainImageWrapper.attr('data-src', imageUrl);
            mainImageWrapper.attr('data-large_image', imageUrl);
            mainImageWrapper.attr('data-thumb', imageUrl);
            mainImage.attr('data-src', imageUrl);
            mainImage.attr('data-large_image', imageUrl);
            mainImage.attr('data-o_src', imageUrl);
            mainImage.attr('data-o_srcset', '');

            const anchor = mainImage.parent('a');
            if (anchor.length) {
                anchor.attr('href', imageUrl);
                anchor.attr('data-src', imageUrl);
            }

            $('.flex-control-nav img').first().attr('src', imageUrl);
            $('.woocommerce-product-gallery__image').first().attr('data-thumb', imageUrl);

            setTimeout(function () {
                if ($.fn.zoom) {
                    $('.woocommerce-product-gallery__image').trigger('zoom.destroy');
                }
                $('.woocommerce-product-gallery').trigger('woocommerce_gallery_init');
                $('form.cart').trigger('woocommerce_gallery_reset_slideshow');
                if (typeof $.fn.zoom === 'function') {
                    $('.woocommerce-product-gallery__image').each(function () {
                        const $el = $(this);
                        if ($el.find('img').length) {
                            $el.zoom();
                        }
                    });
                }
            }, 150);

            $(window).trigger('resize');
        }

        const form = $('form.cart');
        $('#photowooshop-dynamic-inputs').remove();
        const dynamicInputs = $('<div id="photowooshop-dynamic-inputs" style="display:none;"></div>');

        dynamicInputs.append(`<input type="hidden" name="photowooshop_custom_image" value="${imageUrl}">`);
        dynamicInputs.append(`<input type="hidden" name="photowooshop_individual_images" value='${JSON.stringify(serverImageUrls)}'>`);
        dynamicInputs.append(`<input type="hidden" name="photowooshop_mockups" value='${JSON.stringify(mockupUrls)}'>`);

        const textValues = [];
        Object.keys(previousTexts).sort().forEach(function (idx) {
            if (previousTexts[idx]) textValues.push(previousTexts[idx]);
        });
        const text = textValues.join(' | ');
        if (text) dynamicInputs.append(`<input type="hidden" name="photowooshop_custom_text" value="${text}">`);
        if (audioUrl) dynamicInputs.append(`<input type="hidden" name="photowooshop_audio_url" value="${audioUrl}">`);

        form.append(dynamicInputs);
        $('#photowooshop-open-editor').text('Szerkesztés módosítása');

        if (mainImageWrapper.length) {
            $('html, body').animate({
                scrollTop: mainImageWrapper.offset().top - 100
            }, 500);
        }
        $('#photowooshop-upload-msg').fadeOut();
    }
});
