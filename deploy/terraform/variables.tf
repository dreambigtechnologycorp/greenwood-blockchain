# Explicit deployment profile; machines and SSH access are external prerequisites.
variable "ssh_user" {
  description = "Existing SSH account with approved sudo access"
  type        = string
  validation {
    condition     = can(regex("^[a-z_][a-z0-9_-]*$", var.ssh_user))
    error_message = "Supply a valid Linux account name."
  }
}
variable "node_addresses" {
  description = "Management IPv4 addresses for every host in the selected profile"
  type        = map(string)
  validation {
    condition = (contains([4, 7], length(var.node_addresses)) &&
      length(distinct(values(var.node_addresses))) == length(var.node_addresses) &&
    alltrue([for address in values(var.node_addresses) : can(cidrnetmask("${address}/32"))]))
    error_message = "Supply distinct IPv4 management addresses for the selected profile."
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

variable "topology_profile" {
  type    = string
  default = "four"
  validation {
    condition     = contains(["four", "seven"], var.topology_profile)
    error_message = "Select the explicit four or seven profile."
  }
}
