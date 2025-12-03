<template>
    <component
        :dusk="currentField.attribute"
        :is="currentField.fullWidth ? 'FullWidthField' : 'default-field'"
        :field="currentField"
        :errors="errors"
        :show-help-text="showHelpText"
        full-width-content>
        <template #field>

            <div ref="flexibleFieldContainer">
                <form-nova-flexible-content-group
                    v-for="(group, index) in orderedGroups"
                    :dusk="currentField.attribute + '-' + index"
                    :key="group.key"
                    :field="currentField"
                    :group="group"
                    :index="index"
                    :resource-name="resourceName"
                    :resource-id="resourceId"
                    :errors="errors"
                    :mode="mode"
                    @move-up="moveUp(group.key)"
                    @move-down="moveDown(group.key)"
                    @remove="remove(group.key)"
                />
            </div>

            <component
                :layouts="layouts"
                :is="currentField.menu.component"
                :field="currentField"
                :limit-counter="limitCounter"
                :limit-per-layout-counter="limitPerLayoutCounter"
                :errors="errors"
                :resource-name="resourceName"
                :resource-id="resourceId"
                @addGroup="addGroup($event)"
            />

        </template>
    </component>
</template>

<script>

import FullWidthField from './FullWidthField';
import Sortable from 'sortablejs'
import {DependentFormField, HandlesValidationErrors, FormEvents, mapProps} from 'laravel-nova';
import Group from '../group';

export default {
    mixins: [HandlesValidationErrors, DependentFormField, FormEvents],

    props: {
        ...mapProps(['mode']),
    },

    components: {FullWidthField},

    computed: {
        layouts() {
            return this.currentField.layouts || false
        },
        orderedGroups() {
            return this.order.reduce((groups, key) => {
                groups.push(this.groups[key]);
                return groups;
            }, []);
        },

        limitCounter() {
            if (this.currentField.limit === null || typeof (this.currentField.limit) == "undefined") {
                return null;
            }

            return this.currentField.limit - Object.keys(this.groups).length;
        },

        limitPerLayoutCounter() {
            return this.layouts.reduce((layoutCounts, layout) => {
                if (layout.limit === null) {
                    layoutCounts[layout.name] = null;

                    return layoutCounts;
                }

                let count = Object.values(this.groups).filter(group => group.name === layout.name).length;

                layoutCounts[layout.name] = layout.limit - count;

                return layoutCounts;
            }, {});
        },

    },

    data() {
        return {
            order: [],
            groups: {},
            files: {},
            sortableInstance: null,
            nestedFieldListeners: {}
        };
    },

    mounted() {
        // Set up listeners after initial setup
        this.$nextTick(() => {
            this.setupNestedFieldListeners();
        });
    },

    beforeUnmount() {
        if (this.sortableInstance) {
            this.sortableInstance.destroy();
        }
        // Clean up nested field listeners
        this.cleanupNestedFieldListeners();
    },

    methods: {
        /*
         * Set the initial, internal value for the field.
         */
        setInitialValue() {
            this.value = this.currentField.value || [];
            this.files = {};

            this.populateGroups();
            this.$nextTick(this.initSortable.bind(this));
        },

        /**
         * Fill the given FormData object with the field's internal value.
         */
        fill(formData) {
            let key, group;

            this.value = [];
            this.files = {};

            for (var i = 0; i < this.order.length; i++) {
                key = this.order[i];
                group = this.groups[key].serialize();

                // Only serialize the group's non-file attributes
                this.value.push({
                    layout: group.layout,
                    key: group.key,
                    attributes: group.attributes
                });

                // Attach the files for formData appending
                this.files = {...this.files, ...group.files};
            }

            this.appendFieldAttribute(formData, this.currentField.attribute);
            formData.append(this.currentField.attribute, this.value.length ? JSON.stringify(this.value) : '');

            // Append file uploads
            for (let file in this.files) {
                formData.append(file, this.files[file]);
            }

            this.$nextTick(this.initSortable.bind(this));
        },

        /**
         * Register given field attribute into the parsable flexible fields register
         */
        appendFieldAttribute(formData, attribute) {
            let registered = [];

            if (formData.has('___nova_flexible_content_fields')) {
                registered = JSON.parse(formData.get('___nova_flexible_content_fields'));
            }

            registered.push(attribute);

            formData.set('___nova_flexible_content_fields', JSON.stringify(registered));
        },

        /**
         * Set the displayed layouts from the field's current value
         */
        populateGroups() {
            this.order.splice(0, this.order.length);
            this.groups = {};

            for (var i = 0; i < this.value.length; i++) {
                this.addGroup(
                    this.getLayout(this.value[i].layout),
                    this.value[i].attributes,
                    this.value[i].key,
                    this.currentField.collapsed,
                    true // Skip event handling during population
                );
            }

            // Re-setup field listeners after groups are populated
            this.$nextTick(() => {
                this.setupNestedFieldListeners();
            });
        },

        setupNestedFieldListeners() {
            // Clean up any existing listeners first
            this.cleanupNestedFieldListeners();

            // Set up listeners for all nested fields within each group
            Object.values(this.groups).forEach(group => {
                if (group && group.fields) {
                    group.fields.forEach(field => {
                        // Listen to the change event emitted by individual field components
                        const changeEventName = `${field.attribute}-change`;

                        const changeListener = (value) => {

                            this.$nextTick(() => {

                                let flexibleData = [];
                                for (var i = 0; i < this.order.length; i++) {
                                    let key = this.order[i];
                                    let group = this.groups[key] ? this.groups[key].serialize() : null;

                                    if (group) {
                                        flexibleData.push({
                                            layout: group.layout,
                                            key: group.key,
                                            attributes: this.cleanAttributeNames(group.attributes, group.key)
                                        });
                                    }
                                }

                                this.emitFieldValueChange(this.currentField.attribute, flexibleData);
                            });
                        };

                        // Store listener for cleanup
                        this.nestedFieldListeners[changeEventName] = changeListener;

                        // Listen to the change event
                        Nova.$on(changeEventName, changeListener);
                    });
                }
            });
        },

        /**
         * Clean up all nested field listeners
         */
        cleanupNestedFieldListeners() {
            Object.keys(this.nestedFieldListeners).forEach(eventName => {
                Nova.$off(eventName, this.nestedFieldListeners[eventName]);
            });
            this.nestedFieldListeners = {};
        },

        /**
         * Clean field attribute names by removing the group key prefix
         * and convert array-like attributes to proper JSON arrays
         */
        cleanAttributeNames(attributes, groupKey) {
            const cleanedAttributes = {};
            const arrayAttributes = {};
            const prefix = groupKey + '__';
            const arrayPattern = /^(.+)\[(\d+)\]$/;

            // First pass: remove prefix and detect array attributes
            Object.keys(attributes).forEach(key => {
                let cleanKey;
                if (key.startsWith(prefix)) {
                    cleanKey = key.substring(prefix.length);
                } else {
                    cleanKey = key;
                }

                const arrayMatch = cleanKey.match(arrayPattern);
                if (arrayMatch) {
                    // Array-like attribute: years[0], years[1], etc.
                    const baseName = arrayMatch[1];
                    const index = parseInt(arrayMatch[2], 10);

                    if (!arrayAttributes[baseName]) {
                        arrayAttributes[baseName] = [];
                    }
                    arrayAttributes[baseName].push({index, value: attributes[key]});
                } else {
                    // Regular attribute
                    cleanedAttributes[cleanKey] = attributes[key];
                }
            });

            // Second pass: convert array attributes to proper arrays
            Object.keys(arrayAttributes).forEach(baseName => {
                // Sort by index to preserve order
                arrayAttributes[baseName].sort((a, b) => a.index - b.index);
                // Create array with values in correct positions
                cleanedAttributes[baseName] = arrayAttributes[baseName].map(item => item.value);
            });

            return cleanedAttributes;
        },

        /**
         * Retrieve layout definition from its name
         */
        getLayout(name) {
            if (!this.layouts) return;
            return this.layouts.find(layout => layout.name == name);
        },

        /**
         * Append the given layout to flexible content's list
         */
        addGroup(layout, attributes, key, collapsed, skipEventHandling = false) {
            if (!layout) return;

            collapsed = collapsed || false;

            let fields = attributes || JSON.parse(JSON.stringify(layout.fields)),
                group = new Group(layout.name, layout.title, fields, this.currentField, key, collapsed);

            this.groups[group.key] = group;
            this.order.push(group.key);

            // Only set up listeners and emit changes for new groups (not during population)
            if (!skipEventHandling) {
                this.$nextTick(() => {
                    this.setupNestedFieldListeners();
                    // Build and emit current flexible data
                    let flexibleData = [];
                    for (var i = 0; i < this.order.length; i++) {
                        let key = this.order[i];
                        let group = this.groups[key] ? this.groups[key].serialize() : null;
                        if (group) {
                            flexibleData.push({
                                layout: group.layout,
                                key: group.key,
                                attributes: this.cleanAttributeNames(group.attributes, group.key)
                            });
                        }
                    }
                    this.emitFieldValueChange(this.currentField.attribute, flexibleData);
                });
            }
        },

        /**
         * Move a group up
         */
        moveUp(key) {
            let index = this.order.indexOf(key);

            if (index <= 0) return;

            this.order.splice(index - 1, 0, this.order.splice(index, 1)[0]);
        },

        /**
         * Move a group down
         */
        moveDown(key) {
            let index = this.order.indexOf(key);

            if (index < 0 || index >= this.order.length - 1) return;

            this.order.splice(index + 1, 0, this.order.splice(index, 1)[0]);
        },

        /**
         * Remove a group
         */
        remove(key) {
            let index = this.order.indexOf(key);

            if (index < 0) return;

            this.order.splice(index, 1);
            delete this.groups[key];

            // Re-setup listeners after group removal and emit change
            this.$nextTick(() => {
                this.setupNestedFieldListeners();
                // Build and emit current flexible data
                let flexibleData = [];
                for (var i = 0; i < this.order.length; i++) {
                    let key = this.order[i];
                    let group = this.groups[key] ? this.groups[key].serialize() : null;
                    if (group) {
                        flexibleData.push({
                            layout: group.layout,
                            key: group.key,
                            attributes: this.cleanAttributeNames(group.attributes, group.key)
                        });
                    }
                }
                this.emitFieldValueChange(this.currentField.attribute, flexibleData);
            });
        },


        initSortable() {
            const containerRef = this.$refs['flexibleFieldContainer']

            if (!containerRef || this.sortableInstance) {
                return;
            }

            this.sortableInstance = Sortable.create(containerRef, {
                ghostClass: 'nova-flexible-content-sortable-ghost',
                dragClass: 'nova-flexible-content-sortable-drag',
                chosenClass: 'nova-flexible-content-sortable-chosen',
                direction: 'vertical',
                handle: '.nova-flexible-content-drag-button',
                scrollSpeed: 5,
                animation: 500,
                onEnd: (evt) => {
                    const item = evt.item;
                    const key = item.id;
                    const oldIndex = evt.oldIndex;
                    const newIndex = evt.newIndex;

                    if (newIndex < oldIndex) {
                        this.moveUp(key);
                    } else if (newIndex > oldIndex) {
                        this.moveDown(key);
                    }
                }
            });
        },
    }
}
</script>
