<template>
  <div v-if="!loading">
    <h3 class="text-lg font-serif pb-3 uppercase">
      My Shopping Cart
      <span v-if="!loading">- {{ cart.count }} item(s)</span>
    </h3>

    <div
      class="mt-3 bg-white text-base overflow-hidden shadow rounded-lg"
      v-if="cart.count > 0"
    >
      <div class="w-full lg:table">
        <div
          class="uppercase font-bold font-serif text-sm text-primary select-none hidden lg:table-row"
        >
          <div class="p-5 border-b lg:table-cell">Product</div>
          <div :class="colClass">Unit Price</div>
          <div :class="colClass">Qty.</div>
          <div :class="colClass">Length (mm)</div>
          <div :class="colClass">Width (mm)</div>
          <div :class="colClass">Weight</div>
          <div :class="colClass">Cut Charges</div>
          <div :class="colClass">Subtotal (Ex GST)</div>
          <div :class="colClass"></div>
        </div>

        <CartItem
          v-for="item in cartItems"
          :key="item.rowId"
          :item="item"
          :updating="updating"
          v-on:update="updateCart"
          v-on:remove="deleteItem"
        />
      </div>

       <div class="flex items-center flex-wrap justify-center bg-gray-lightest p-3 md:justify-end">
          <div class="w-48 mr-10">
            <div class="flex justify-between" v-if="cart.weight > 0">
              <span>Total Weight :</span>
              <span class="text-black">{{ cart.weight }}kg</span>
            </div>

            <div class="flex justify-between">
              <span>Subtotal <small>(Ex GST)</small> :</span>
              <span class="text-black">{{ tba(cart.subtotal) }}</span>
            </div>

            <div class="flex justify-between" v-if="cart.shipping && cart.shipping.available">
              <span>Shipping Charges :</span>
              <span class="text-black">{{ tba(cart.shippingCharges) }}</span>
            </div>

            <div class="flex justify-between">
              <span>GST :</span>
              <span class="text-black">{{ tba(cart.tax) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Total :</span>
              <span class="text-black">{{ tba(cart.total) }}</span>
            </div>
          </div>

          <div class="flex flex-wrap mt-2 md:flex-no-wrap">
            <a :href="`${$root.url}/checkout`" class="btn btn-primary w-full md:w-auto md:mr-3">Proceed to Quote</a>
            <a
              :href="`${$root.url}/checkout`"
              class="btn btn-primary w-full mt-3 md:w-auto md:mt-0"
              v-if="!cart.tba"
            >Proceed to Pay</a>
          </div>
        </div>

      <div class="clearfix"></div>
    </div>

    <div class="shadow p-6 bg-white text-lg rounded-lg" v-else>
      <span>Shopping Cart is empty!</span>
    </div>
  </div>
</template>

<script>
import CartItem from "./CartItem";
export default {
  name: "cart",
  components: {
    CartItem
  },
  data: () => ({
    colClass: "p-5 border-b lg:table-cell lg:text-center",
    cart: {},
    loading: true,
    updating: false
  }),

  computed: {
    cartItems() {
      return _.orderBy(this.cart.items, "options.sort");
    }
  },

  methods: {
    getCart() {
      axios
        .get("/cart/get")
        .then(resp => {
          this.loading = false;
          this.cart = resp.data;
        })
        .catch(err => console.log(err));
    },
    updateCart(item) {
      this.updating = true;
      axios
        .put(`/cart/${item.rowId}`, item)
        .then(resp => {
          this.cart = resp.data;
          this.updating = false;
        })
        .catch(err => {
          console.log(err);
          this.updating = false;
        });
    },
    deleteItem(rowId) {
      if (this.updating) return false;
      this.updating = true;
      axios
        .delete(`/cart/${rowId}`)
        .then(resp => {
          this.updating = false;
          this.cart = resp.data;
          this.$root.$emit("del-cart-item", resp.data.count);
        })
        .catch(err => {
          console.log(err);
          this.updating = false;
        });
    },
    tba(n) {
      return this.cart.tba ? "TBA" : "$" + n;
    }
  },
  created() {
    this.getCart();
  }
};
</script>