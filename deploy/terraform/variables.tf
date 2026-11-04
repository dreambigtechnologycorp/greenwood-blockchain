# Fixed four-node topology; machines and SSH access are external prerequisites.
variable "ssh_user" {
  description = "Existing SSH account with approved sudo access"
  type        = string
  validation {
    condition     = can(regex("^[a-z_][a-z0-9_-]*$", var.ssh_user))
    error_message = "Supply a valid Linux account name."
  }
}
variable "node_addresses" {
  description = "Management IPv4 addresses for node-1 through node-4"
  type        = map(string)
  validation {
    condition = (sort(keys(var.node_addresses)) == tolist(["node-1", "node-2", "node-3", "node-4"]) &&
      length(distinct(values(var.node_addresses))) == 4 &&
    alltrue([for address in values(var.node_addresses) : can(cidrnetmask("${address}/32"))]))
    error_message = "Supply four distinct IPv4 management addresses."
  }
}
variable "gateway_address" {
  description = "LAN gateway IPv4 address"
  type        = string
  validation {
    condition     = can(cidrnetmask("${var.gateway_address}/32"))
    error_message = "Supply a valid IPv4 gateway address."
  }
}
