/**
 * Block: PMPro Login Form
 *
 * Add a login form to any page or post.
 *
 */

/**
 * Block dependencies
 */
import Inspector from "./inspector";

/**
 * Internal block libraries
 */
const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const { Fragment } = wp.element;
/**
 * Register block
 */
export default registerBlockType("pmpro/login-form", {
	title: __("Login Form", "paid-memberships-pro"),
	description: __(
		"Displays a login form for Paid Memberships Pro.",
		"paid-memberships-pro"
	),
	category: "pmpro",
	icon: {
		background: "#2997c8",
		foreground: "#ffffff",
		src: "unlock",
	},
	keywords: [
		__("pmpro", "paid-memberships-pro"),
		__("login", "paid-memberships-pro"),
		__("form", "paid-memberships-pro"),
	],
	supports: {},
	edit: (props) => {
		return [
			<Fragment>
				<Inspector {...props} />
				<div className="pmpro-block-element">
					<span>{__("Paid Memberships Pro", "paid-memberships-pro")}</span>
					<span>{__("Login Form", "paid-memberships-pro")}</span>
				</div>
			</Fragment>,
		];
	},
	save() {
		return null;
	},
});
