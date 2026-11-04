# Greenwood Production Physical Topology
#
# Authoritative declared infrastructure (frozen):
#
#   node-1 10.77.0.100  Validator A + Provider A + controller/build node
#   node-2 10.77.0.101  Validator B + Provider B
#   node-3 10.77.0.102  Validator C + Provider C
#   node-4 10.77.0.103  gateway/router + application edge (no protocol role)
#
# node-4 carries NO Greenwood protocol role: it is NOT a validator, provider,
# contributor or reward participant. Actual WAN/LAN interfaces, routes and DNS
# are supplied through the approved fresh-cluster network plan, not this topology.
#
# Controller (node-1) is an operations designation ONLY (release staging,
# inventory generation, Ansible execution). It is simultaneously Validator A
# and Provider A on the protocol; the concerns stay separate in the derived lists.
#
# Dual-role (validator + provider) is fine, but provider workloads must never
# starve the validator process or consensus (see provider resource controls).

locals {
  operator_host = "node-1"

  # Node topology - all three compute nodes carry validator + provider.
  nodes = {
    "node-1" = {
      address         = var.node_addresses["node-1"]
      ssh_user        = var.ssh_user
      roles           = ["validator", "provider"]
      controller      = true
      validator_label = "A"
      provider_label  = "A"
      provider_host   = true
      gateway         = false
      gateway_address = null
      architecture    = "aarch64"
    }
    "node-2" = {
      address         = var.node_addresses["node-2"]
      ssh_user        = var.ssh_user
      roles           = ["validator", "provider"]
      controller      = false
      validator_label = "B"
      provider_label  = "B"
      provider_host   = true
      gateway         = false
      gateway_address = null
      architecture    = "aarch64"
    }
    "node-3" = {
      address         = var.node_addresses["node-3"]
      ssh_user        = var.ssh_user
      roles           = ["validator", "provider"]
      controller      = false
      validator_label = "C"
      provider_label  = "C"
      provider_host   = true
      gateway         = false
      gateway_address = null
      architecture    = "aarch64"
    }
    "node-4" = {
      address         = var.node_addresses["node-4"]
      ssh_user        = var.ssh_user
      roles           = [] # NO Greenwood protocol role
      controller      = false
      validator_label = null
      provider_label  = null
      provider_host   = false
      gateway         = true
      gateway_address = var.gateway_address
      architecture    = "aarch64"
    }
  }

  # Derived lists for the Ansible inventory generator -------------------------
  node_hostnames = keys(local.nodes)
  node_addresses = [for k, v in local.nodes : v.address]

  validators = [
    for k, v in local.nodes : {
      hostname        = k
      address         = v.address
      validator_label = v.validator_label
      controller      = v.controller
    }
    if contains(try(v.roles, []), "validator")
  ]

  providers = [
    for k, v in local.nodes : {
      hostname       = k
      address        = v.address
      provider_label = v.provider_label
    }
    if contains(try(v.roles, []), "provider")
  ]

  controllers = [
    for k, v in local.nodes : {
      hostname = k
      address  = v.address
    }
    if v.controller
  ]

  gateways = [
    for k, v in local.nodes : {
      hostname        = k
      address         = v.address
      gateway_address = v.gateway_address
    }
    if v.gateway
  ]
}

# Validation ----------------------------------------------------------------
locals {
  validator_set_ok = sort([for v in local.validators : v.validator_label]) == tolist(["A", "B", "C"])
  provider_set_ok  = sort([for p in local.providers : "${p.hostname}:${p.provider_label}"]) == tolist(["node-1:A", "node-2:B", "node-3:C"])
  controller_ok    = sort([for c in local.controllers : c.hostname]) == tolist(["node-1"])
  gateway_ok       = sort([for g in local.gateways : g.hostname]) == tolist(["node-4"])
  node4_clean      = local.nodes["node-4"].gateway && length(local.nodes["node-4"].roles) == 0 && local.nodes["node-4"].validator_label == null && local.nodes["node-4"].provider_label == null
  topology_ok      = local.validator_set_ok && local.provider_set_ok && local.controller_ok && local.gateway_ok && local.node4_clean
  topology_failure = local.topology_ok ? "" : "TOPOLOGY INVALID"
}

# Node information output for Ansible inventory generation
output "node_topology" {
  description = "Machine-readable node topology for Ansible inventory generation"
  value = {
    for k, v in local.nodes :
    k => {
      address         = v.address
      ssh_user        = v.ssh_user
      roles           = v.roles
      controller      = v.controller
      validator_label = v.validator_label
      provider_label  = v.provider_label
      provider_host   = v.provider_host
      gateway         = v.gateway
      gateway_address = v.gateway_address
      architecture    = v.architecture
    }
  }
}

# Summary output for human readability
output "summary" {
  description = "Summary of configured nodes"
  value = {
    node_count      = length(local.nodes)
    node_hostnames  = local.node_hostnames
    node_addresses  = local.node_addresses
    validator_count = length(local.validators)
    provider_count  = length(local.providers)
    controller_host = local.controllers[0].hostname
    gateway_host    = local.gateways[0].hostname
    validator_set   = sort([for v in local.validators : v.validator_label])
    provider_set    = sort([for p in local.providers : p.provider_label])
  }
}
