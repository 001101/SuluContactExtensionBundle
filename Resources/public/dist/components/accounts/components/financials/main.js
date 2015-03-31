define(["widget-groups"],function(a){"use strict";var b="#bank-account-form",c={headline:"contact.accounts.title"},d={overlayIdTermsOfPayment:"overlayContainerTermsOfPayment",overlayIdTermsOfDelivery:"overlayContainerTermsOfDelivery",overlaySelectorTermsOfPayment:"#overlayContainerTermsOfPayment",overlaySelectorTermsOfDelivery:"#overlayContainerTermsOfDelivery",cgetTermsOfDeliveryURL:"api/termsofdeliveries",cgetTermsOfPaymentURL:"api/termsofpayments"};return{view:!0,layout:function(){return{content:{width:"fixed"},sidebar:{width:"max",cssClasses:"sidebar-padding-50"}}},templates:["/admin/contact/template/account/financials"],initialize:function(){this.options=this.sandbox.util.extend(!0,{},c,this.options),this.saved=!0,this.form="#financials-form",this.termsOfDeliveryInstanceName="terms-of-delivery",this.termsOfPaymentInstanceName="terms-of-payment",this.setHeaderBar(!0),this.render(),this.listenForChange(),this.options.data&&this.options.data.id&&a.exists("account-detail")&&this.initSidebar("/admin/widget-groups/account-detail?account=",this.options.data.id)},initSidebar:function(a,b){this.sandbox.emit("sulu.sidebar.set-widget",a+b)},render:function(){var a=this.options.data;this.html(this.renderTemplate(this.templates[0])),this.initForm(a),this.bindDomEvents(),this.bindCustomEvents()},initTermsSelect:function(a){this.preselectedTermsOfPaymentId=a.termsOfPayment?[a.termsOfPayment.id]:"",this.preselectedTermsOfDeliveryId=a.termsOfDelivery?[a.termsOfDelivery.id]:"",this.sandbox.start([{name:"select@husky",options:{el:"#termsOfPayment",instanceName:this.termsOfPaymentInstanceName,multipleSelect:!1,defaultLabel:this.sandbox.translate("public.please-choose"),valueName:"terms",repeatSelect:!1,direction:"bottom",editable:!0,resultKey:"termsOfPayments",preSelectedElements:this.preselectedTermsOfPaymentId,url:d.cgetTermsOfPaymentURL}},{name:"select@husky",options:{el:"#termsOfDelivery",instanceName:this.termsOfDeliveryInstanceName,multipleSelect:!1,defaultLabel:this.sandbox.translate("public.please-choose"),valueName:"terms",repeatSelect:!1,direction:"bottom",editable:!0,resultKey:"termsOfDeliveries",preSelectedElements:this.preselectedTermsOfDeliveryId,url:d.cgetTermsOfDeliveryURL}}])},initForm:function(a){var b=this.sandbox.form.create(this.form);this.initFormHandling(a),b.initialized.then(function(){this.setFormData(a),this.initTermsSelect(a)}.bind(this))},setFormData:function(a){this.sandbox.emit("sulu.contact-form.add-collectionfilters",this.form),this.sandbox.form.setData(this.form,a).then(function(){this.sandbox.start(this.form)}.bind(this)).fail(function(a){this.sandbox.logger.error("An error occured when setting data!",a)}.bind(this))},bindDomEvents:function(){this.sandbox.dom.keypress(this.form,function(a){13===a.which&&(a.preventDefault(),this.submit())}.bind(this))},bindCustomEvents:function(){this.sandbox.on("sulu.header.toolbar.delete",function(){this.sandbox.emit("sulu.contacts.account.delete",this.options.data.id)},this),this.sandbox.on("sulu.contacts.accounts.financials.saved",function(a){this.options.data=a,this.setFormData(a),this.setHeaderBar(!0)},this),this.sandbox.on("sulu.header.toolbar.save",function(){this.submit()},this),this.sandbox.on("sulu.header.back",function(){this.sandbox.emit("sulu.contacts.accounts.list")},this)},submit:function(){if(this.sandbox.form.validate(this.form)){var a=this.sandbox.form.getData(this.form);this.sandbox.emit("sulu.contacts.accounts.financials.save",a)}},setHeaderBar:function(a){if(a!==this.saved){var b=this.options.data&&this.options.data.id?"edit":"add";this.sandbox.emit("sulu.header.toolbar.state.change",b,a,!0)}this.saved=a},listenForChange:function(){this.sandbox.dom.on(this.form,"change",function(){this.setHeaderBar(!1)}.bind(this),".changeListener select, .changeListener input, .changeListener textarea"),this.sandbox.dom.on(this.form,"keyup",function(){this.setHeaderBar(!1)}.bind(this),".changeListener select, .changeListener input, .changeListener textarea"),this.sandbox.on("sulu.contact-form.changed",function(){this.setHeaderBar(!1)}.bind(this)),this.sandbox.on("husky.select."+this.termsOfDeliveryInstanceName+".selected.item",function(a){a>0&&(this.selectedTermsOfDelivery=a,this.setHeaderBar(!1))}.bind(this)),this.sandbox.on("husky.select."+this.termsOfPaymentInstanceName+".selected.item",function(a){a>0&&(this.selectedTermsOfPayment=a,this.setHeaderBar(!1))}.bind(this))},initFormHandling:function(a){this.sandbox.on("sulu.contact-form.initialized",function(){this.sandbox.emit("sulu.contact-form.add-collectionfilters",this.form);var c=this.sandbox.form.create(b);c.initialized.then(function(){this.setFormData(a)}.bind(this))}.bind(this)),this.sandbox.start([{name:"contact-form@sulucontact",options:{el:"#financials-form"}}])}}});