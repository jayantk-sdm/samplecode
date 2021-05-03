<template>
	<div class="flex flex-wrap px-5 py-5 border-b lg:table-row lg:px-0">
		<div class="p-2 w-full lg:table-cell lg:w-auto lg:border-b lg:p-5">
			<div class="flex items-center">
				<div class="mr-3">
					<img width="70" :src="`${$root.url}/storage/images/products/${item.options.image}`" alt="image">
				</div>			 
			<div>
			<h3>{{ item.name }}</h3>
			<div class="font-bold"><span class="text-primary text-xs font-serif"> STOCK CODE :</span> {{ item.id }}</div>
			<span class="block">{{ item.options.description }}</span>
			</div>
			</div>

			
		</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Unit Price</div>
			{{ tba(item.options.unitPrice.toFixed(2)) }} 
		</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Qty.</div>
			<NumberInput 
			:default="item.qty" 
			:min="1" 
			v-model="form.qty" 
			:class="['p-1 w-16 text-center border', {'opacity-50' : updating}]"
			@input="update('qty')" 
			:disabled="updating" />	
		</div>
		<div :class="colClass">
			<div :class="smLabelClass">Length (mm)</div>
		
			<NumberInput 
				:default="item.options.length" 
				:min="15" 
				:max="item.options.maxLength"
				v-model="form.length" 
				:class="['p-1 w-32 text-center border', {'opacity-50' : updating}]" 
				@input="update('length')" 
				:disabled="updating" 
				v-if="item.options.custom && item.options.length" />			

			<span v-else-if="item.options.length">{{ item.options.length }}</span>

			<span v-else>N/A</span>
			
		</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Width (mm)</div>

			<NumberInput 
			:default="item.options.width" 
			:min="15" 
			:max="item.options.maxWidth"
			v-model="form.width" 
			:class="['p-1 w-32 text-center border', {'opacity-50' : updating}]" 
			@input="update('width')" 
			:disabled="updating" 
			v-if="item.options.custom && item.options.pack == 'sq_mtr' && item.options.width" />

			<span v-else-if="item.options.width">{{ item.options.width }}</span>
			
			<span v-else>N/A</span>

		</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Weight</div>
			{{ item.options.weight > 0 ? item.options.weight.toFixed(2) + 'kg' : 'N/A' }} 
		</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Cut Charges</div>
			
			${{ (item.extraCharges.cut > 0 && item.qty > 1 ? `${item.extraCharges.cut.toFixed(2)} x ${item.qty}` : item.extraCharges.cut.toFixed(2)) }} 
			</div>
		<div :class="colClass"> 
			<div :class="smLabelClass">Subtotal <small>(Ex GST)</small></div>
			{{ tba(item.price.toFixed(2)) }} 
		</div>

		<div :class="colClass">
			<a href="#" title="Remove item" :disabled="updating" @click.prevent="remove(item.rowId)">
				<i class="fa fa-times"></i>
			</a>
		</div>


</div>
</template>

<script>

import NumberInput from './NumberInput.vue';

export default {
	name: 'CartItem',
	props : ['item', 'updating'],
	components: {
		NumberInput
	},
	data: () => ({
		colClass: 'p-2 w-1/2 lg:table-cell lg:w-auto lg:align-middle lg:text-center lg:border-b lg:p-5',
		smLabelClass: 'mt-2 text-xs text-primary uppercase text-grey font-bold lg:hidden',
		form: {
			rowId: null,
			id: null,
			qty: null,
			length: null,
			width: null,
		}
	}),
	created() {
		this.form.rowId = this.item.rowId;
		this.form.id = this.item.id;
		this.form.qty = this.item.qty;
		this.form.length = this.item.options.length;
		this.form.width = this.item.options.width;
	},
	methods: {
		update(prop) {
			let val = prop == 'qty' ? this.item[prop] : this.item.options[prop];			
			if (this.form[prop] != '' && this.form[prop] != val) {
				this.$emit('update', this.form);
			}
		},
		remove(rowId) {
			this.$emit('remove', rowId);
		},
		tba(n) {
      	return this.item.options.tba ? 'TBA' : '$' + n
    	}
	}
}
</script>