<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ph-blog-inline-editor"
        data-blog-inline-editor
        x-data="blogInlineBlockEditor({
            state: $wire.$entangle('{{ $getStatePath() }}'),
            blockDefinitions: @js($getBlockDefinitions()),
            mediaOptions: @js($getMediaOptions()),
            labels: {
                restoreTitle: @js(trans_message('blog_cms.inline_editor_restore_title')),
                restoreDescription: @js(trans_message('blog_cms.inline_editor_restore_description')),
                restoreAction: @js(trans_message('blog_cms.inline_editor_restore_action')),
                dismissAction: @js(trans_message('blog_cms.inline_editor_dismiss_action')),
                addBlock: @js(trans_message('blog_cms.inline_editor_add_block')),
                duplicate: @js(trans_message('blog_cms.inline_editor_duplicate')),
                moveUp: @js(trans_message('blog_cms.inline_editor_move_up')),
                moveDown: @js(trans_message('blog_cms.inline_editor_move_down')),
                remove: @js(trans_message('blog_cms.inline_editor_remove')),
                typeSlash: @js(trans_message('blog_cms.inline_editor_type_slash')),
                emptyMedia: @js(trans_message('blog_cms.inline_editor_empty_media')),
                addItem: @js(trans_message('blog_cms.inline_editor_add_item')),
                addImage: @js(trans_message('blog_cms.inline_editor_add_image')),
                addRow: @js(trans_message('blog_cms.inline_editor_add_row')),
                addColumn: @js(trans_message('blog_cms.inline_editor_add_column')),
                paragraphPlaceholder: @js(trans_message('blog_cms.inline_editor_paragraph_placeholder')),
                headingPlaceholder: @js(trans_message('blog_cms.inline_editor_heading_placeholder')),
                quotePlaceholder: @js(trans_message('blog_cms.inline_editor_quote_placeholder')),
                codePlaceholder: @js(trans_message('blog_cms.inline_editor_code_placeholder')),
                urlPlaceholder: @js(trans_message('blog_cms.inline_editor_url_placeholder')),
                captionPlaceholder: @js(trans_message('blog_cms.inline_editor_caption_placeholder')),
                altPlaceholder: @js(trans_message('blog_cms.inline_editor_alt_placeholder')),
                titlePlaceholder: @js(trans_message('blog_cms.inline_editor_title_placeholder')),
                descriptionPlaceholder: @js(trans_message('blog_cms.inline_editor_description_placeholder')),
                buttonPlaceholder: @js(trans_message('blog_cms.inline_editor_button_placeholder')),
                tableHeaderPlaceholder: @js(trans_message('blog_cms.inline_editor_table_header_placeholder')),
                tableCellPlaceholder: @js(trans_message('blog_cms.inline_editor_table_cell_placeholder')),
            },
        })"
    >
        <div class="ph-blog-inline-editor__restore" x-show="hasLocalDraft" x-cloak>
            <div>
                <div class="ph-blog-inline-editor__restore-title" x-text="labels.restoreTitle"></div>
                <div class="ph-blog-inline-editor__restore-text" x-text="labels.restoreDescription"></div>
            </div>
            <div class="ph-blog-inline-editor__restore-actions">
                <button type="button" x-on:click="restoreLocalDraft()" x-text="labels.restoreAction"></button>
                <button type="button" x-on:click="dismissLocalDraft()" x-text="labels.dismissAction"></button>
            </div>
        </div>

        <div class="ph-blog-inline-editor__canvas">
            <template x-for="(block, index) in state" :key="`${index}-${block.type}`">
                <section class="ph-blog-inline-editor__block" :class="{ 'is-active': activeIndex === index }">
                    <div class="ph-blog-inline-editor__toolbar">
                        <button type="button" x-on:click="openBlockMenu(index)" :aria-label="labels.addBlock" :title="labels.addBlock">+</button>
                        <button type="button" x-on:click="moveBlock(index, -1)" :aria-label="labels.moveUp" :title="labels.moveUp">↑</button>
                        <button type="button" x-on:click="moveBlock(index, 1)" :aria-label="labels.moveDown" :title="labels.moveDown">↓</button>
                        <button type="button" x-on:click="duplicateBlock(index)" x-text="labels.duplicate"></button>
                        <button type="button" x-on:click="removeBlock(index)" x-text="labels.remove"></button>
                    </div>

                    <template x-if="block.type === 'paragraph'">
                        <textarea
                            class="ph-blog-inline-editor__textarea"
                            x-model="block.data.content"
                            x-on:focus="activeIndex = index"
                            x-on:input="touchState()"
                            x-on:keydown="handleParagraphKeydown($event, index)"
                            :placeholder="labels.paragraphPlaceholder"
                            :data-blog-block-input="index"
                            rows="4"
                        ></textarea>
                    </template>

                    <template x-if="block.type === 'heading'">
                        <div class="ph-blog-inline-editor__split">
                            <select x-model.number="block.data.level" x-on:change="touchState()">
                                <option value="2">H2</option>
                                <option value="3">H3</option>
                                <option value="4">H4</option>
                            </select>
                            <input x-model="block.data.content" x-on:input="touchState()" :placeholder="labels.headingPlaceholder" :data-blog-block-input="index">
                        </div>
                    </template>

                    <template x-if="block.type === 'list'">
                        <div class="ph-blog-inline-editor__stack">
                            <select x-model="block.data.style" x-on:change="touchState()">
                                <option value="unordered">{{ trans_message('blog_cms.editor_list_unordered') }}</option>
                                <option value="ordered">{{ trans_message('blog_cms.editor_list_ordered') }}</option>
                            </select>
                            <template x-for="(item, itemIndex) in block.data.items" :key="itemIndex">
                                <div class="ph-blog-inline-editor__row">
                                    <input x-model="item.value" x-on:input="touchState()" :placeholder="labels.paragraphPlaceholder">
                                    <button type="button" x-on:click="removeListItem(index, itemIndex)" x-text="labels.remove"></button>
                                </div>
                            </template>
                            <button type="button" class="ph-blog-inline-editor__secondary-action" x-on:click="addListItem(index)" x-text="labels.addItem"></button>
                        </div>
                    </template>

                    <template x-if="block.type === 'quote'">
                        <div class="ph-blog-inline-editor__stack">
                            <textarea class="ph-blog-inline-editor__textarea" x-model="block.data.content" x-on:input="touchState()" :placeholder="labels.quotePlaceholder" rows="3" :data-blog-block-input="index"></textarea>
                            <input x-model="block.data.caption" x-on:input="touchState()" :placeholder="labels.captionPlaceholder">
                        </div>
                    </template>

                    <template x-if="block.type === 'image'">
                        <div class="ph-blog-inline-editor__stack">
                            <select x-model="block.data.url" x-on:change="touchState()" :data-blog-block-input="index">
                                <option value="" x-text="labels.emptyMedia"></option>
                                <template x-for="media in mediaEntries()" :key="media[0]">
                                    <option :value="media[0]" x-text="media[1]"></option>
                                </template>
                            </select>
                            <input x-model="block.data.alt" x-on:input="touchState()" :placeholder="labels.altPlaceholder">
                            <input x-model="block.data.caption" x-on:input="touchState()" :placeholder="labels.captionPlaceholder">
                        </div>
                    </template>

                    <template x-if="block.type === 'gallery'">
                        <div class="ph-blog-inline-editor__stack">
                            <template x-for="(image, imageIndex) in block.data.images" :key="imageIndex">
                                <div class="ph-blog-inline-editor__nested">
                                    <select x-model="image.url" x-on:change="touchState()">
                                        <option value="" x-text="labels.emptyMedia"></option>
                                        <template x-for="media in mediaEntries()" :key="media[0]">
                                            <option :value="media[0]" x-text="media[1]"></option>
                                        </template>
                                    </select>
                                    <input x-model="image.alt" x-on:input="touchState()" :placeholder="labels.altPlaceholder">
                                    <button type="button" x-on:click="removeGalleryImage(index, imageIndex)" x-text="labels.remove"></button>
                                </div>
                            </template>
                            <button type="button" class="ph-blog-inline-editor__secondary-action" x-on:click="addGalleryImage(index)" x-text="labels.addImage"></button>
                        </div>
                    </template>

                    <template x-if="block.type === 'table'">
                        <div class="ph-blog-inline-editor__stack">
                            <div class="ph-blog-inline-editor__table-scroll">
                                <table class="ph-blog-inline-editor__table">
                                    <thead>
                                        <tr>
                                            <template x-for="(header, headerIndex) in block.data.headers" :key="headerIndex">
                                                <th>
                                                    <input x-model="header.value" x-on:input="touchState()" :placeholder="labels.tableHeaderPlaceholder">
                                                </th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(row, rowIndex) in block.data.rows" :key="rowIndex">
                                            <tr>
                                                <template x-for="(cell, cellIndex) in row.cells" :key="cellIndex">
                                                    <td>
                                                        <input x-model="cell.value" x-on:input="touchState()" :placeholder="labels.tableCellPlaceholder">
                                                    </td>
                                                </template>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div class="ph-blog-inline-editor__row">
                                <button type="button" x-on:click="addTableRow(index)" x-text="labels.addRow"></button>
                                <button type="button" x-on:click="addTableColumn(index)" x-text="labels.addColumn"></button>
                            </div>
                        </div>
                    </template>

                    <template x-if="block.type === 'code'">
                        <div class="ph-blog-inline-editor__stack">
                            <input x-model="block.data.language" x-on:input="touchState()" placeholder="PHP">
                            <textarea class="ph-blog-inline-editor__textarea ph-blog-inline-editor__code" x-model="block.data.content" x-on:input="touchState()" :placeholder="labels.codePlaceholder" rows="8" :data-blog-block-input="index"></textarea>
                        </div>
                    </template>

                    <template x-if="block.type === 'divider'">
                        <hr class="ph-blog-inline-editor__divider">
                    </template>

                    <template x-if="block.type === 'callout'">
                        <div class="ph-blog-inline-editor__stack">
                            <select x-model="block.data.variant" x-on:change="touchState()">
                                <option value="info">{{ trans_message('blog_cms.editor_block_variant_info') }}</option>
                                <option value="success">{{ trans_message('blog_cms.editor_block_variant_success') }}</option>
                                <option value="warning">{{ trans_message('blog_cms.editor_block_variant_warning') }}</option>
                            </select>
                            <input x-model="block.data.title" x-on:input="touchState()" :placeholder="labels.titlePlaceholder" :data-blog-block-input="index">
                            <textarea class="ph-blog-inline-editor__textarea" x-model="block.data.content" x-on:input="touchState()" :placeholder="labels.descriptionPlaceholder" rows="3"></textarea>
                        </div>
                    </template>

                    <template x-if="block.type === 'embed'">
                        <input x-model="block.data.url" x-on:input="touchState()" :placeholder="labels.urlPlaceholder" :data-blog-block-input="index">
                    </template>

                    <template x-if="block.type === 'cta'">
                        <div class="ph-blog-inline-editor__stack">
                            <input x-model="block.data.label" x-on:input="touchState()" :placeholder="labels.buttonPlaceholder" :data-blog-block-input="index">
                            <input x-model="block.data.url" x-on:input="touchState()" :placeholder="labels.urlPlaceholder">
                            <textarea class="ph-blog-inline-editor__textarea" x-model="block.data.description" x-on:input="touchState()" :placeholder="labels.descriptionPlaceholder" rows="2"></textarea>
                        </div>
                    </template>

                    <div class="ph-blog-inline-editor__slash-menu" x-show="menuIndex === index" x-on:click.outside="closeBlockMenu()" x-cloak>
                        <div class="ph-blog-inline-editor__slash-hint" x-text="labels.typeSlash"></div>
                        <template x-for="definition in blockDefinitions" :key="definition.type">
                            <button type="button" x-on:click="insertFromMenu(index, definition.type)">
                                <span x-text="definition.label"></span>
                            </button>
                        </template>
                    </div>
                </section>
            </template>
        </div>
    </div>
</x-dynamic-component>
