const registerBlogInlineBlockEditor = (Alpine) => {
    Alpine.data('blogInlineBlockEditor', ({ state, statePath, wire, blockDefinitions = [], mediaOptions = {}, acceptedImageTypes = [], labels = {} }) => ({
        state,
        statePath,
        wire,
        blockDefinitions,
        mediaOptions,
        acceptedImageTypes,
        labels,
        activeIndex: 0,
        menuIndex: null,
        hasLocalDraft: false,
        pendingLocalDraft: null,
        serverSnapshot: '',
        persistTimer: null,
        uploadingImages: {},
        uploadErrors: {},

        init() {
            this.state = this.normalizeState(this.state);
            this.serverSnapshot = this.serialize(this.state);
            this.detectLocalDraft();

            this.$watch('state', () => {
                this.scheduleLocalDraftPersist();
            });

            window.addEventListener('blog-article-saved', () => {
                this.clearLocalDraft();
                this.serverSnapshot = this.serialize(this.state);
            });
        },

        normalizeState(value) {
            const source = Array.isArray(value) && value.length > 0 ? value : [this.createBlock('paragraph')];

            return source.map((block) => {
                const type = typeof block?.type === 'string' ? block.type : 'paragraph';

                return this.normalizeBlock({
                    type,
                    data: this.mergeDefaultData(type, block?.data),
                });
            });
        },

        normalizeBlock(block) {
            return {
                type: block.type,
                data: block.data,
            };
        },

        blockDefinition(type) {
            return this.blockDefinitions.find((definition) => definition.type === type)
                ?? this.blockDefinitions.find((definition) => definition.type === 'paragraph')
                ?? { type: 'paragraph', defaultData: { content: '' } };
        },

        mergeDefaultData(type, data = {}) {
            const defaults = this.clone(this.blockDefinition(type)?.defaultData ?? {});
            const normalized = typeof data === 'object' && data !== null ? this.clone(data) : {};
            const merged = { ...defaults, ...normalized };

            if (type === 'list') {
                merged.items = Array.isArray(merged.items) && merged.items.length > 0 ? merged.items : [{ value: '' }];
            }

            if (type === 'gallery') {
                merged.images = Array.isArray(merged.images) && merged.images.length > 0 ? merged.images : [{ url: '', alt: '' }];
            }

            if (type === 'table') {
                merged.headers = Array.isArray(merged.headers) && merged.headers.length > 0 ? merged.headers : [{ value: '' }, { value: '' }];
                merged.rows = Array.isArray(merged.rows) && merged.rows.length > 0 ? merged.rows : [
                    { cells: merged.headers.map(() => ({ value: '' })) },
                ];
                merged.rows = merged.rows.map((row) => ({
                    cells: Array.isArray(row.cells) && row.cells.length > 0 ? row.cells : merged.headers.map(() => ({ value: '' })),
                }));
            }

            return merged;
        },

        createBlock(type) {
            return this.normalizeBlock({
                type: this.blockDefinition(type)?.type ?? 'paragraph',
                data: this.clone(this.blockDefinition(type)?.defaultData ?? { content: '' }),
            });
        },

        addBlock(type = 'paragraph', index = this.state.length - 1) {
            this.state.splice(index + 1, 0, this.createBlock(type));
            this.activeIndex = index + 1;
            this.closeBlockMenu();
            this.touchState();
            this.focusBlock(this.activeIndex);
        },

        openBlockMenu(index) {
            this.activeIndex = index;
            this.menuIndex = index;
        },

        closeBlockMenu() {
            this.menuIndex = null;
        },

        insertFromMenu(index, type) {
            const current = this.state[index];
            const canConvert = current?.type === 'paragraph' && String(current?.data?.content ?? '').trim() === '';

            if (canConvert) {
                this.convertBlock(index, type);
                return;
            }

            this.addBlock(type, index);
        },

        convertBlock(index, type) {
            this.state.splice(index, 1, this.createBlock(type));
            this.activeIndex = index;
            this.closeBlockMenu();
            this.touchState();
            this.focusBlock(index);
        },

        duplicateBlock(index) {
            this.state.splice(index + 1, 0, this.normalizeBlock(this.clone(this.state[index])));
            this.activeIndex = index + 1;
            this.touchState();
            this.focusBlock(this.activeIndex);
        },

        removeBlock(index) {
            if (this.state.length === 1) {
                this.state.splice(0, 1, this.createBlock('paragraph'));
                this.activeIndex = 0;
                this.touchState();
                this.focusBlock(0);
                return;
            }

            this.state.splice(index, 1);
            this.activeIndex = Math.max(0, index - 1);
            this.closeBlockMenu();
            this.touchState();
            this.focusBlock(this.activeIndex);
        },

        moveBlock(index, direction) {
            const nextIndex = index + direction;

            if (nextIndex < 0 || nextIndex >= this.state.length) {
                return;
            }

            const [block] = this.state.splice(index, 1);
            this.state.splice(nextIndex, 0, block);
            this.activeIndex = nextIndex;
            this.touchState();
            this.focusBlock(nextIndex);
        },

        handleParagraphKeydown(event, index) {
            const content = String(this.state[index]?.data?.content ?? '');

            if (event.key === '/' && content.trim() === '') {
                event.preventDefault();
                this.openBlockMenu(index);
                return;
            }

            const target = event.target;
            const cursorIsAtEnd = target.selectionStart === target.value.length && target.selectionEnd === target.value.length;

            if (event.key === 'Enter' && !event.shiftKey && cursorIsAtEnd) {
                event.preventDefault();
                this.addBlock('paragraph', index);
            }
        },

        addListItem(index) {
            this.state[index].data.items ??= [];
            this.state[index].data.items.push({ value: '' });
            this.touchState();
        },

        removeListItem(index, itemIndex) {
            this.state[index].data.items.splice(itemIndex, 1);

            if (this.state[index].data.items.length === 0) {
                this.state[index].data.items.push({ value: '' });
            }

            this.touchState();
        },

        addGalleryImage(index) {
            this.state[index].data.images ??= [];
            this.state[index].data.images.push({ url: '', alt: '' });
            this.touchState();
        },

        removeGalleryImage(index, imageIndex) {
            this.state[index].data.images.splice(imageIndex, 1);

            if (this.state[index].data.images.length === 0) {
                this.state[index].data.images.push({ url: '', alt: '' });
            }

            this.touchState();
        },

        addTableRow(index) {
            const headerCount = Math.max(1, this.state[index].data.headers?.length ?? 2);
            this.state[index].data.rows ??= [];
            this.state[index].data.rows.push({
                cells: Array.from({ length: headerCount }, () => ({ value: '' })),
            });
            this.touchState();
        },

        addTableColumn(index) {
            this.state[index].data.headers ??= [];
            this.state[index].data.headers.push({ value: '' });
            this.state[index].data.rows ??= [];
            this.state[index].data.rows.forEach((row) => {
                row.cells ??= [];
                row.cells.push({ value: '' });
            });
            this.touchState();
        },

        mediaEntries() {
            return Object.entries(this.mediaOptions ?? {});
        },

        uploadKey(index, imageIndex = null) {
            return imageIndex === null ? `${index}` : `${index}.${imageIndex}`;
        },

        isUploadingImage(index, imageIndex = null) {
            return this.uploadingImages[this.uploadKey(index, imageIndex)] === true;
        },

        uploadError(index, imageIndex = null) {
            return this.uploadErrors[this.uploadKey(index, imageIndex)] ?? '';
        },

        setUploadError(index, imageIndex, message) {
            this.uploadErrors = {
                ...this.uploadErrors,
                [this.uploadKey(index, imageIndex)]: message,
            };
        },

        setUploadingImage(index, imageIndex, isUploading) {
            this.uploadingImages = {
                ...this.uploadingImages,
                [this.uploadKey(index, imageIndex)]: isUploading,
            };
        },

        imagePayload(index, imageIndex = null) {
            if (imageIndex === null) {
                return this.state[index]?.data ?? null;
            }

            return this.state[index]?.data?.images?.[imageIndex] ?? null;
        },

        uploadImage(event, index, imageIndex = null) {
            const file = event.target.files?.[0] ?? null;
            const payload = this.imagePayload(index, imageIndex);
            const alt = String(payload?.alt ?? '').trim();

            if (!file || !payload) {
                return;
            }

            if (alt === '') {
                this.setUploadError(index, imageIndex, this.labels.altRequiredBeforeUpload ?? '');
                event.target.value = '';
                return;
            }

            this.setUploadError(index, imageIndex, '');
            this.setUploadingImage(index, imageIndex, true);

            this.wire.upload(
                'inline_media_upload',
                file,
                async () => {
                    try {
                        const asset = await this.wire.uploadInlineMedia(alt, payload.caption ?? '');

                        if (!asset?.url) {
                            throw new Error(this.labels.uploadFailed ?? '');
                        }

                        this.mediaOptions = {
                            ...(this.mediaOptions ?? {}),
                            [asset.url]: asset.label ?? asset.url,
                        };
                        payload.url = asset.url;
                        payload.alt = asset.alt_text ?? alt;
                        payload.caption = payload.caption || asset.caption || '';
                        this.touchState();
                    } catch (error) {
                        this.setUploadError(index, imageIndex, this.extractUploadError(error));
                    } finally {
                        this.setUploadingImage(index, imageIndex, false);
                        event.target.value = '';
                    }
                },
                () => {
                    this.setUploadingImage(index, imageIndex, false);
                    this.setUploadError(index, imageIndex, this.labels.uploadFailed ?? '');
                    event.target.value = '';
                },
            );
        },

        extractUploadError(error) {
            const errors = error?.response?.data?.errors ?? error?.errors ?? null;

            if (errors && typeof errors === 'object') {
                const first = Object.values(errors).flat().find(Boolean);

                if (first) {
                    return String(first);
                }
            }

            return error?.message || this.labels.uploadFailed || '';
        },

        focusBlock(index) {
            this.$nextTick(() => {
                this.$root.querySelector(`[data-blog-block-input="${index}"]`)?.focus();
            });
        },

        touchState() {
            this.state = this.state.map((block) => ({
                type: block.type,
                data: this.clone(block.data),
            }));
            this.syncLivewireState();
        },

        syncLivewireState() {
            if (!this.statePath || !this.wire?.$set) {
                return;
            }

            this.wire.$set(this.statePath, this.dehydrate(this.state), false);
        },

        draftKey() {
            return `blog-inline-editor:${window.location.pathname}`;
        },

        detectLocalDraft() {
            const rawDraft = localStorage.getItem(this.draftKey());

            if (!rawDraft) {
                return;
            }

            try {
                const parsed = this.normalizeState(JSON.parse(rawDraft));

                if (this.serialize(parsed) !== this.serverSnapshot) {
                    this.pendingLocalDraft = parsed;
                    this.hasLocalDraft = true;
                }
            } catch {
                localStorage.removeItem(this.draftKey());
            }
        },

        restoreLocalDraft() {
            if (!this.pendingLocalDraft) {
                return;
            }

            this.state = this.clone(this.pendingLocalDraft);
            this.hasLocalDraft = false;
            this.pendingLocalDraft = null;
            this.touchState();
        },

        dismissLocalDraft() {
            this.clearLocalDraft();
        },

        scheduleLocalDraftPersist() {
            window.clearTimeout(this.persistTimer);
            this.persistTimer = window.setTimeout(() => {
                localStorage.setItem(this.draftKey(), this.serialize(this.state));
            }, 350);
        },

        clearLocalDraft() {
            localStorage.removeItem(this.draftKey());
            this.hasLocalDraft = false;
            this.pendingLocalDraft = null;
        },

        serialize(value) {
            return JSON.stringify(this.dehydrate(value));
        },

        dehydrate(value) {
            return this.clone(value).map((block) => ({
                type: block.type,
                data: block.data,
            }));
        },

        clone(value) {
            if (value === undefined) {
                return undefined;
            }

            return JSON.parse(JSON.stringify(value));
        },
    }));
};

if (window.Alpine) {
    registerBlogInlineBlockEditor(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => registerBlogInlineBlockEditor(window.Alpine), { once: true });
}
